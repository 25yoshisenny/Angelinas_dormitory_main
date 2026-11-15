<?php
// Called only by CLI setup. NULL means not initialized; zero is an intentional rate.
function migrate_fixed_utilities(PDO $db,string $database): void {
    foreach(['fixed_electricity','fixed_water'] as $column) {
        $q=$db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');$q->execute([$database,'rooms',$column]);
        if(!(int)$q->fetchColumn()) $db->exec('ALTER TABLE rooms ADD COLUMN '.$column.' BIGINT NULL CHECK('.$column.'>=0)');
    }
    $examples=['101'=>[65000,18000],'102'=>[85000,22000],'103'=>[110000,28000],'104'=>[75000,20000]];
    $db->beginTransaction();
    try {
        foreach($db->query('SELECT id,number,capacity,fixed_electricity,fixed_water FROM rooms FOR UPDATE')->fetchAll() as $r) {
            // Capacity-only estimate when no measured consumption/appliance information exists.
            [$electricity,$water]=$examples[$r['number']]??[45000+10000*(int)$r['capacity'],10000+3000*(int)$r['capacity']];
            if($r['fixed_electricity']!==null && $r['fixed_water']!==null) continue;
            $db->prepare('UPDATE rooms SET fixed_electricity=COALESCE(fixed_electricity,?),fixed_water=COALESCE(fixed_water,?) WHERE id=?')->execute([$electricity,$water,$r['id']]);
            $db->prepare('INSERT INTO audit_logs(action,record_type,record_id,details) VALUES(?,?,?,?)')->execute(['Initialized fixed room utilities','rooms',$r['id'],'Configurable monthly estimates in centavos: electricity '.$electricity.', water '.$water.'. Existing bills unchanged.']);
        }
        $db->commit();
    } catch(Throwable $e) { $db->rollBack();throw $e; }
}
