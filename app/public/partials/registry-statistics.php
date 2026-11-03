<?php if(!empty($registryStats)): ?>
<dl class="registry-stats" aria-label="Student ID registry statistics">
    <?php foreach([
        'total'=>'Total approved Student IDs',
        'active'=>'Active eligible IDs',
        'unclaimed'=>'Unclaimed IDs',
        'claimed'=>'Claimed IDs',
        'accounts'=>'Registered student accounts',
        'rejected'=>'Rejected registrations',
        'recent'=>'IDs imported this week',
    ] as $registryKey=>$registryLabel): ?>
        <div class="registry-stat<?= in_array($registryKey,['total','active','unclaimed'],true)?' registry-stat--primary':'' ?><?= $registryKey==='active'?' registry-stat--eligible':'' ?>">
            <dt><?= htmlspecialchars($registryLabel,ENT_QUOTES,'UTF-8') ?></dt>
            <dd data-stat="<?= $registryKey ?>"><?= (int)$registryStats[$registryKey] ?></dd>
        </div>
    <?php endforeach; ?>
</dl>
<?php endif; ?>
