<?php
use App\Src\Services\StorageMaintenanceService;
$storageEscape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$storageNumber=static fn($value)=>$value===null?'Unavailable':number_format((int)$value);
$storageDb=$storageSnapshot['database']??[];
$storageRuntime=$storageSnapshot['runtime']??null;
$storageFolderStatus=static function(array $folder): string {
    return match($folder['status']) {
        'ready'=>'Readable / writable', 'read_only'=>'Read only', 'missing'=>'Not created',
        'unreadable'=>'Unavailable', 'limited'=>'Partial scan', 'unsafe_root'=>'Check configuration',
        'linked_root'=>'Linked folder skipped', 'overlap'=>'Shared folder — counted once', default=>'Unavailable'
    };
};
?>
<section class="admin-section" id="storage-maintenance" aria-labelledby="storage-heading" data-storage-status-url="admin_storage_status.php">
    <h2 id="storage-heading">Storage &amp; Maintenance</h2>
    <div class="storage-panel">
        <div class="storage-header">
            <div><h3>System storage</h3><p>Live MySQL records and configured runtime folders. Refreshing checks status only; it does not delete files or records.</p></div>
            <button class="btn btn--secondary" type="button" id="storage-refresh">Refresh status</button>
        </div>
        <p id="storage-status-message" class="storage-message" role="status" aria-live="polite" data-state="<?= $storageSnapshot===null?'error':($storageSnapshot['status']==='warning'?'warning':'ok') ?>"><?= $storageEscape($storageError ?: (!empty($storageSnapshot['warnings'])?implode(' ',$storageSnapshot['warnings']):'Status loaded from the system.')) ?></p>
        <div class="storage-metrics">
            <div class="storage-metric"><span>Runtime files</span><strong data-storage-metric="runtime_bytes"><?= $storageRuntime===null?'Unavailable':(!$storageRuntime['complete']?'At least ':'').StorageMaintenanceService::formatBytes($storageRuntime['bytes']) ?></strong><small data-storage-metric="runtime_files"><?= $storageRuntime===null?'No measurement available':(!$storageRuntime['complete']?'At least ':'').$storageNumber($storageRuntime['files']).' files counted' ?></small></div>
            <div class="storage-metric"><span>MySQL allocation</span><strong data-storage-metric="allocated_bytes"><?= StorageMaintenanceService::formatBytes($storageDb['allocated_bytes']??null) ?></strong><small>Estimated table and index allocation</small></div>
            <div class="storage-metric"><span>Saved summaries</span><strong data-storage-metric="saved_summaries"><?= $storageNumber($storageDb['saved_summaries']??null) ?></strong><small>Completed records in MySQL</small></div>
            <div class="storage-metric"><span>Saved Nutshells</span><strong data-storage-metric="nutshells"><?= $storageNumber($storageDb['nutshells']??null) ?></strong><small>Completed records in MySQL</small></div>
        </div>
        <div class="storage-health"><span data-storage-metric="connection"><?= !empty($storageDb['connected'])?'MySQL connected':'Connection unavailable' ?></span><span>Jobs in database: <strong data-storage-metric="queued_jobs"><?= $storageNumber($storageDb['queued_jobs']??null) ?></strong> queued · <strong data-storage-metric="processing_jobs"><?= $storageNumber($storageDb['processing_jobs']??null) ?></strong> processing</span></div>
        <div class="storage-table-scroll" tabindex="0" role="region" aria-label="Runtime folder measurements">
            <table class="storage-table"><thead><tr><th scope="col">Storage category</th><th scope="col">Files</th><th scope="col">Size</th><th scope="col">Folder status</th></tr></thead><tbody>
            <?php foreach(['uploads'=>'Temporary uploads','audio'=>'Generated audio / cache','temporary'=>'Processing temporary files','logs'=>'Application logs','models'=>'Storage model cache','backups'=>'Private backups'] as $key=>$label):
                $folder=$storageRuntime['folders'][$key]??null;
                $measured=$folder&&$folder['readable'];$prefix=$folder&&!$folder['complete']?'At least ':'';
            ?>
                <tr data-storage-folder="<?= $storageEscape($key) ?>"><th scope="row"><?= $storageEscape($label) ?></th><td data-folder-files><?= $measured?$prefix.$storageNumber($folder['files']):'Unavailable' ?></td><td data-folder-bytes><?= $measured?$prefix.StorageMaintenanceService::formatBytes($folder['bytes']):'Unavailable' ?></td><td data-folder-status><?= $folder?$storageEscape($storageFolderStatus($folder)):'Unavailable' ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <div class="storage-footer"><p>Uploads are temporary and normally removed after processing; a zero upload count does not mean your saved summaries are missing. Summaries are stored in MySQL. Job counts reflect stored states, not a worker health check.</p><p><?= $storageEscape($storageSnapshot['scope']??'Configured runtime folders only; Python environments and external model caches are not included.') ?></p><p>Last checked: <time id="storage-checked-at"<?= $storageSnapshot?' datetime="'.$storageEscape($storageSnapshot['checked_at']).'"':'' ?>><?= $storageSnapshot?$storageEscape($storageSnapshot['checked_at']):'Not available' ?></time></p></div>
    </div>
</section>
