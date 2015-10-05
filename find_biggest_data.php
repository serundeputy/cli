<?php

define('THRESHOLD', 1024);

/**
 * Convert bytes to human readable format
 *
 * @param integer bytes Size in bytes to convert
 * @return string
 */
function bytesToSize($bytes, $precision = 2)
{  
    $kilobyte = 1024;
    $megabyte = $kilobyte * 1024;
    $gigabyte = $megabyte * 1024;
    $terabyte = $gigabyte * 1024;
   
    if (($bytes >= 0) && ($bytes < $kilobyte)) {
        return $bytes . ' B';
 
    } elseif (($bytes >= $kilobyte) && ($bytes < $megabyte)) {
        return round($bytes / $kilobyte, $precision) . ' KB';
 
    } elseif (($bytes >= $megabyte) && ($bytes < $gigabyte)) {
        return round($bytes / $megabyte, $precision) . ' MB';
 
    } elseif (($bytes >= $gigabyte) && ($bytes < $terabyte)) {
        return round($bytes / $gigabyte, $precision) . ' GB';
 
    } elseif ($bytes >= $terabyte) {
        return round($bytes / $terabyte, $precision) . ' TB';
    } else {
        return $bytes . ' B';
    }
}

$db = new PDO(
    'mysql:dbname=pantheon;host=127.0.0.1;port=3307',
    'pantheon',
    '');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tables = array();
$result = $db->query('SHOW TABLES');
while ($row = $result->fetch(PDO::FETCH_NUM)) {
  array_push($tables, $row[0]);
}

foreach ($tables as $table) {
  //echo 'Table: ' . $table . PHP_EOL;

  $primary_key = array();
  $result = $db->query('SHOW INDEX FROM `' . $table . '`');
  while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    if ($row['Key_name'] === 'PRIMARY') {
      array_push($primary_key, $row['Column_name']);
    }
  }

  if (empty($primary_key)) {
    echo 'Table ' . $table . ' has no primary key.' . PHP_EOL;
    continue;
  }

  $pk_columns = '`' . implode('`,`', $primary_key) . '`';

  $columns = array();
  $result = $db->query('SHOW COLUMNS FROM `' . $table . '`');
  while ($row = $result->fetch(PDO::FETCH_NUM)) {
    array_push($columns, $row[0]);
  }

  $storage = 'LENGTH(' . implode(') + LENGTH(', $columns) . ')';
  $query = 'SELECT ' . $storage . ' AS row_size, ' . $pk_columns . ' FROM ' . $table . ' ORDER BY row_size DESC LIMIT 10';
  //echo $query . PHP_EOL;
  $result = $db->query($query);
  $table_introduction = 'Table ' . $table . ' (' . implode(',', $primary_key) . ')' . PHP_EOL;
  $shown_introudction = false;
  while ($row = $result->fetch(PDO::FETCH_NUM)) {
    $row_size = array_shift($row);
    if ($row_size > THRESHOLD) {
      if (!$shown_introudction) {
        echo $table_introduction;
        $shown_introudction = true;
      }
      $key = implode(',', $row);
      echo '    (' . $key . '): ' . bytesToSize($row_size, 0) . PHP_EOL;
    }
  }
}
