<?php // Demo stand-in for the GPU worker: php tools/simulate.php
require __DIR__.'/../public/api/db.php';$db=db();echo "Simulating. Ctrl+C to stop.\n";
while(true){$lot=random_int(1,3);$n=random_int(1,48);$now=gmdate('Y-m-d H:i:s');
 $db->prepare("UPDATE bays SET status=CASE status WHEN 'free' THEN 'busy' WHEN 'busy' THEN 'free' ELSE status END,updated_at=? WHERE lot_id=? AND num=?")->execute([$now,$lot,$n]);
 $db->prepare('INSERT INTO gpu_stats(lot_id,fps,latency_ms,util,created_at)VALUES(?,?,?,?,?)')->execute([$lot,230+random_int(0,20),7+random_int(0,30)/10,55+random_int(0,35),$now]);usleep(900000);}
