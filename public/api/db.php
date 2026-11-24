<?php
function db():PDO{
  static $p;if($p)return $p;
  $c=require __DIR__.'/config.php';@mkdir(dirname($c['db']),0775,true);
  $p=new PDO('sqlite:'.$c['db'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $p->exec('PRAGMA foreign_keys=ON;PRAGMA journal_mode=WAL;PRAGMA busy_timeout=4000');
  $p->exec(file_get_contents(__DIR__.'/../../schema.sql'));
  if(!$p->query('SELECT 1 FROM lots LIMIT 1')->fetch()){
    $ins=$p->prepare('INSERT INTO lots(name,lat,lng,rate)VALUES(?,?,?,?)');
    foreach([['Central Garage',30.0444,31.2357,20],['Riverside Lot',30.0131,31.2089,15],['Airport Express',30.1219,31.4056,30]] as $l)$ins->execute($l);
    $b=$p->prepare('INSERT INTO bays(lot_id,num,status)VALUES(?,?,?)');
    foreach([1,2,3] as $lot)for($n=1;$n<=48;$n++)$b->execute([$lot,$n,'free']);
  }
  return $p;
}
