<?php namespace Sqobot;

class TaskQueue extends Task {
  static function table(array $args) {
    $table = &$args['table'];
    return $table ? cfg('dbPrefix').$table : Queue::tableName();
  }
    static function idList(array $list) {
      return 'id IN ('.join(', ', array_unique(S($list, '(int) ?'))).')';
    }

  function do_add(array $args = null) {
    if ($args === null or !opt(1)) {
      return print 'queue add SITE URL --extra={json} --exact --table=queue';
    }

    if (empty($args['exact'])) {
      $fields = Queue::hop(opt(1), opt(0));
    } else {
      $fields = array('url' => opt(1), 'site' => opt(0));
    }

    $queue = Queue::make($fields);

    if ($extra = &$args['extra']) {
      $extra = S::json("$extra", true);
      if (!is_array($extra)) {
        return print 'Bad JSON string passed with --extra - it must evaluate to'.
                     ' array but got:'.PHP_EOL.var_export($extra, true).PHP_EOL;
      }

      $queue->extra($extra);
    }

    $queue->table = static::table($args);
    $queue->createIgnore();

    if ($queue->url !== opt(1)) {
      echo 'Hopped to URL: '.$queue->url, PHP_EOL;
    }

    if (!$queue->id) {
      echo 'This item is already in queue.', PHP_EOL;
    } else {
      echo "Created queue item #{$queue->id} in {$queue->table()} table.", PHP_EOL;

      if ($extra) {
        echo 'Extra data attached:', PHP_EOL,
             var_export($extra, true), PHP_EOL;
      }
    }
  }

  function do_clear(array $args = null) {
    if ($args === null) {
      return print 'queue clear [SITE] --failed --table=queue'.PHP_EOL.
                   'queue clear ID [ID [...]] --table=queue';
    }

    $table = static::table($args);
    $where = array();

    if (is_numeric(opt(0))) {
      $where[] = static::idList(opt());
    } else {
      empty($args['failed']) or $where[] = 'error != \'\'';
      $site = opt(0) and $where[] = 'site = :site';
    }

    $sql = 'DELETE FROM `'.$table.'`';
    $where and $sql .= ' WHERE '.join(' AND ', $where);
    $count = exec($sql, compact('site'));

    if ($count) {
      $s = $count == 1 ? '' : 's';
      echo "Deleted $count queued item$s from $table table.", PHP_EOL;
    } else {
      echo "Nothing to delete in $table table.", PHP_EOL;
    }
  }

  function do_amend(array $args = null) {
    if ($args === null) {
      return print 'queue amend [SITE]'.PHP_EOL.
                   'queue amend ID [ID [...]]'.PHP_EOL.
                   PHP_EOL.
                   '  --table=queue --like[]=%error-LIKE-OR_';
    }

    $table = static::table($args);

    $sql = 'UPDATE `'.$table.'` SET started = NULL';
    $where = array();

    if (is_numeric(opt(0))) {
      $where[] = static::idList(opt());
    } else {
      $site = opt(0) and $where[] = 'site = :site';
    }

    if ($like = &$args['like']) {
      $where[] = '('.join(' OR ', S((array) $like, function ($expr) {
        return "`error` LIKE ".db()->quote("%$expr%");
      })).')';
    }

    $where and $sql .= ' WHERE '.join(' AND ', $where);
    $count = exec($sql, compact('site'));

    if ($count) {
      $s = $count == 1 ? '' : 's';
      echo "Amended $count queued item$s in $table table.", PHP_EOL;
    } else {
      echo "Nothing to amend in $table table.", PHP_EOL;
    }
  }

  function do_mark(array $args = null) {
    if ($args === null) {
      return print 'queue mark --table=queue';
    }

    if ( $count = Queue::markTimeout(static::table($args)) ) {
      $s = $count == 1 ? '' : 's';
      echo "Marked $count queue item$s as timed out.", PHP_EOL;
    } else {
      echo 'No queue items have timed out.', PHP_EOL;
    }
  }

  function do_(array $args = null) {
    if ($args === null) {
      return print 'queue [do [SITE]] --amend --mark --keep --table=queue';
    }

    empty($args['amend']) or $this->do_amend($args);
    empty($args['mark']) or $this->do_mark($args);

    array_intersect_key($args, array('amend'=>1, 'mark'=>1)) and print PHP_EOL;

    $options = array(
      'site'              => opt(0),
      'table'             => static::table($args),
      'keepDone'          => !empty($args['keep']),
    );

    $started = microtime(true);
    $worker = Sqissor::dequeue($options);
    $duration = microtime(true) - $started;

    if (!$worker) {
      return print 'Queue is empty.';
    }

    $newCount = count($worker->queued);
    $s = $newCount == 1 ? '' : 's';

    ob_start();

    echo "Done with queue item #{$worker->queue->id}. ",
         sprintf('This took %1.2f sec.', $duration), PHP_EOL,
         PHP_EOL;

    static::echoQueueInfo($worker->queue);
    $summary = ob_get_flush();

    echo PHP_EOL, "The worker has added $newCount queue item$s.", PHP_EOL;

    foreach ($worker->queued as $i => $item) {
      echo PHP_EOL, '  ', $i + 1, '.', PHP_EOL,
      static::echoQueueInfo($item);
    }

    $newCount > 3 and print PHP_EOL.$summary;
  }

    static function echoQueueInfo(Queue $queue) {
      echo "  ID:             ", $queue->id, PHP_EOL,
           "  URL:            ", $queue->url, PHP_EOL,
           "  Site:           ", $queue->site, PHP_EOL;

      if ($extra = $queue->extra()) {
        echo "  Extra:          ", S::json($extra), PHP_EOL;
      }
    }

  function do_stats(array $args = null) {
    if ($args === null) {
      return print 'queue stats --table=queue';
    }

    $table = static::table($args);

    $sql = "SELECT site, COUNT(1) AS count, MIN(id) AS min, MAX(id) AS max".
           " FROM `$table` GROUP BY site";
    $stmt = exec($sql);
    $sites = $stmt->fetchAll();
    $stmt->closeCursor();

    $s = count($sites) == 1 ? '' : 's';
    echo "Queue contains ", count($sites), " site$s.", PHP_EOL;

    if (!$sites) {
      return;
    }

    usort($sites, function ($a, $b) { return strcmp($a->site, $b->site); });

    $sql = "SELECT COUNT(1) AS count FROM `$table` WHERE site = ? AND error != ''".
           " AND error NOT LIKE 'Completed OK%' AND started IS NOT NULL";
    $stErrors = prep($sql);

    $sql = "SELECT COUNT(1) AS count FROM `$table` WHERE site = ? AND error = ''".
           " AND started IS NOT NULL";
    $stActive = prep($sql);

    $sql = "SELECT COUNT(1) AS count FROM `$table` WHERE site = ? AND extra != ''";
    $stExtra = prep($sql);

    $sql = "SELECT * FROM `$table` WHERE id IN (?, ?)";
    $stMinMax = prep($sql);

    foreach ($sites as $i => $site) {
      $stErrors->bindParam(1, $site->site);
      $errorCount = EQuery::exec($stErrors)->fetch()->count;
      $stErrors->closeCursor();

      $stActive->bindParam(1, $site->site);
      $activeCount = EQuery::exec($stActive)->fetch()->count;
      $stActive->closeCursor();

      $stExtra->bindParam(1, $site->site);
      $extraCount = EQuery::exec($stExtra)->fetch()->count;
      $stExtra->closeCursor();

      echo PHP_EOL,
           $i + 1, '. ', $site->site, PHP_EOL,
           PHP_EOL,
           '  Total:          ', $site->count, PHP_EOL;

      static::echoCount($site, 'Errors', $stErrors);
      static::echoCount($site, 'Active', $stActive);
      static::echoCount($site, 'With extra', $stExtra);

      if ($site->count) {
        $stMinMax->bindParam(1, $site->min);
        $stMinMax->bindParam(2, $site->max);
        $min = EQuery::exec($stMinMax)->fetch();
        $max = EQuery::exec($stMinMax)->fetch();
        $stMinMax->closeCursor();

        echo '  Min ID:         ', $site->min, ' (created ', $min->created, ')', PHP_EOL,
             '  Max ID:         ', $site->max, ' (created ', $max->created, ')', PHP_EOL;
      }
    }
  }

    static function echoCount($site, $title, \PDOStatement $stmt) {
      $stmt->bindParam(1, $site->site);
      $count = EQuery::exec($stmt)->fetch()->count;
      $stmt->closeCursor();

      printf('  %-16s', "$title:");

      if ($count) {
        echo $count, ' (', $count - $site->count, ')';
      } else {
        echo '-';
      }

      echo PHP_EOL;
    }

  function do_peek(array $args = null) {
    if ($args === null) {
      return print 'queue peek [SITE] --table=queue';
    }

    $queue = Queue::peekFree(opt(0), static::table($args));

    if (!$queue) {
      return print 'Queue is empty.';
    }

    echo 'Next queued item:', PHP_EOL, PHP_EOL;
    static::echoQueueInfo(new Queue($queue));
  }

  function do_url(array $args = null) {
    if ($args === null) {
      return print 'queue url [NAME [...]] --table=queue';
    }

    $table = static::table($args);
    $all = Qurl::all(opt() ?: '');

    if (!$all) {
      return print "No matching 'qurl' options.";
    }

    foreach ($all as $name => $item) {
      echo $name ?: $item, '... ';
      $qurl = Qurl::parse($item);

      echo $count = $qurl->countQueued(false, $table), ' in queue',
           ' (pool = ', $qurl->pool(), ')', PHP_EOL;

      if ($count < $qurl->pool()) {
        if ($count) {
          $last = $qurl->lastQueued($table);
          $page = $last ? (int) $qurl->pageFrom($last->url) : 0;
          echo "  latest page = $page", PHP_EOL;
        } else {
          $page = null;
        }

        echo "  step = {$qurl->step()}", PHP_EOL;
        $queued = array();

        while (++$count <= $qurl->pool()) {
          if ($page === null) {
            $page = $qurl->initial();
          } elseif (!$qurl->end() or $page <= $qurl->end()) {
            $page += $qurl->step();
          } elseif ($qurl->stops()) {
            echo '  stopped at the end', PHP_EOL;
            break;
          } else {
            $page = $qurl->initial();
            echo "  wrapping to page $page", PHP_EOL;
          }

          $item = $qurl->enqueue($page, $table);
          $status = $page;

          if (!$item->id) {
            $status = "_$status";
            --$count;
          }

          if ($item->url !== $qurl->makeURL($page)) {
            $hoppedTo = $qurl->pageFrom($item->url);

            if (isset($hoppedTo)) {
              $status .= "->$hoppedTo";
              $page = $hoppedTo;
            }
          }

          $queued[] = $status;
        }

        if ($queued) {
          $s = count($queued) == 1 ? '' : 's';
          echo "  done, queued ", count($queued), " page$s: ",
               join(' ', $queued), PHP_EOL;
        }

        echo PHP_EOL;
      }
    }
  }
}