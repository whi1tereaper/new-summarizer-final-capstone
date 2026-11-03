<?php
require_once __DIR__.'/../src/whitereaper.php';
require_once __DIR__.'/../src/Services/AdminSecurityService.php';
require_once __DIR__.'/../src/Services/AuditTrailService.php';

use App\Src\Services\AdminSecurityService;
use App\Src\Services\AuditTrailService;

AdminSecurityService::requireVerifiedAdmin('login.php?context=admin');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){
    header('Allow: GET, HEAD');http_response_code(405);exit('Method not allowed.');
}
$escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$filters=['source'=>'','action'=>'','admin_id'=>'','date_from'=>'','date_to'=>'','page'=>1];
$result=['rows'=>[],'total'=>0,'counts'=>['admin'=>0,'registry'=>0],'page'=>1,'pages'=>1,'per_page'=>50,'actions'=>[],'warnings'=>[]];
$error='';
try{
    $filters=AuditTrailService::filters($_GET);
    $result=(new AuditTrailService())->browse((int)$_SESSION['user_id'],$filters);$filters=$result['filters'];
}catch(InvalidArgumentException $exception){
    http_response_code(400);$error=$exception->getMessage();
    // Keep safe fields so correcting one filter does not discard the others.
    foreach(['source','action','admin_id','date_from','date_to'] as $key){
        $value=$_GET[$key]??'';
        if(is_string($value)&&mb_check_encoding($value,'UTF-8')&&mb_strlen($value)<=100&&!preg_match('/[\x00-\x1f\x7f]/',$value))$filters[$key]=trim($value);
    }
}
catch(Throwable $exception){
    error_log('[AuditTrail] View unavailable: '.get_class($exception));
    http_response_code($exception instanceof App\Src\Support\ProcessingError?$exception->httpStatus:503);
    $error='Audit events could not be loaded. Check the database connection and existing audit migrations.';
}
$pageLink=static function(int $page)use($filters):string{
    return '?'.http_build_query(array_filter(array_replace($filters,['page'=>$page]),static fn($value)=>$value!==''));
};
$targetLabels=['user'=>'Account','upload'=>'Uploads','student_registry'=>'Student ID record','registry_import'=>'Import batch'];
$from=$result['total']?($result['page']-1)*$result['per_page']+1:0;
$to=min($result['page']*$result['per_page'],$result['total']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <?php require_once __DIR__ . '/partials/design-system-head.php'; ?>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Audit Logs — DEPENDED</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__.'/assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/admin-audit.css?v=<?= filemtime(__DIR__.'/assets/css/admin-audit.css') ?>">
</head>
<body>
<?php require __DIR__.'/partials/site-nav.php'; ?>
<main class="audit-page">
    <header class="audit-header">
        <div><span class="audit-eyebrow">Administration</span><h1>Audit logs</h1>
        <p>Administrative actions and Student ID activity in one chronological trail.</p></div>
        <span class="audit-access-label">Administrator access only</span>
    </header>

    <section class="audit-panel" aria-label="Audit filters and results">
        <form class="audit-filters" method="get" action="admin_audit_logs.php" aria-label="Filter audit events">
            <label for="audit-source">Source<select id="audit-source" name="source"><option value="">All sources</option>
                <?php foreach(AuditTrailService::SOURCES as $value=>$label): ?><option value="<?= $value ?>" <?= $filters['source']===$value?'selected':'' ?>><?= $escape($label) ?></option><?php endforeach; ?>
            </select></label>
            <label for="audit-action">Event<select id="audit-action" name="action"><option value="">All events</option>
                <?php foreach($result['actions'] as $action): ?><option value="<?= $escape($action) ?>" <?= $filters['action']===$action?'selected':'' ?>><?= $escape(AuditTrailService::label($action)) ?> (<?= $escape($action) ?>)</option><?php endforeach; ?>
            </select></label>
            <label for="audit-actor">Actor ID<input id="audit-actor" type="number" name="admin_id" min="1" max="2147483647" inputmode="numeric" placeholder="Any account" value="<?= $escape($filters['admin_id']) ?>"></label>
            <label for="audit-from">From date<input id="audit-from" type="date" name="date_from" value="<?= $escape($filters['date_from']) ?>"></label>
            <label for="audit-to">To date<input id="audit-to" type="date" name="date_to" value="<?= $escape($filters['date_to']) ?>"></label>
            <div class="audit-filter-actions"><button class="btn btn--primary" type="submit">Apply filters</button><a href="admin_audit_logs.php">Reset</a></div>
        </form>

        <?php if($error): ?><div class="audit-notice" role="alert"><?= $escape($error) ?></div><?php endif; ?>
        <?php foreach($result['warnings'] as $warning): ?><p class="audit-notice" role="status"><?= $escape($warning) ?></p><?php endforeach; ?>
        <div class="audit-results-meta">
            <p id="audit-result-count" role="status"><?= $result['total']?number_format($from).'–'.number_format($to).' of '.number_format($result['total']).' events':'0 matching events' ?></p>
            <div class="audit-source-counts" aria-label="Matching events by source"><span>Administration <strong><?= number_format($result['counts']['admin']) ?></strong></span><span>Student IDs <strong><?= number_format($result['counts']['registry']) ?></strong></span></div>
        </div>

        <div class="audit-table-scroll" role="region" aria-label="Audit event results; scroll horizontally on small screens" tabindex="0">
            <table class="audit-table">
                <caption class="visually-hidden">Recorded administrative and Student ID registry events, newest first. Times use <?= $escape(date_default_timezone_get()) ?>.</caption>
                <thead><tr><th scope="col">Date &amp; time</th><th scope="col">Actor</th><th scope="col">Event</th><th scope="col">Target</th><th scope="col">Connection</th><th scope="col">Details</th></tr></thead>
                <tbody>
                <?php foreach($result['rows'] as $log): ?>
                    <tr data-audit-source="<?= $escape($log['source']) ?>" data-audit-id="<?= (int)$log['event_id'] ?>">
                        <td class="audit-date"><time datetime="<?= $escape(date('c',(int)$log['event_epoch'])) ?>"><?= $escape(date('M j, Y',(int)$log['event_epoch'])) ?><span><?= $escape(date('H:i:s',(int)$log['event_epoch'])) ?></span></time></td>
                        <td><?php if($log['actor_id']!==null): ?><strong><?= $escape($log['actor_name']??'Unavailable account') ?></strong><span class="audit-muted">Account #<?= (int)$log['actor_id'] ?></span><?php else: ?><span>System / unavailable</span><span class="audit-muted">Actor not recorded</span><?php endif; ?></td>
                        <td><span class="audit-source audit-source--<?= $escape($log['source']) ?>"><?= $log['source']==='admin'?'Administration':'Student IDs' ?></span><strong class="audit-event-label"><?= $escape(AuditTrailService::label($log['action'],(string)$log['details'])) ?></strong><span class="audit-event-code"><?= $escape($log['action']) ?> &middot; #<?= (int)$log['event_id'] ?></span></td>
                        <td><?php if($log['entity_type']!==null): ?><?= $escape($targetLabels[$log['entity_type']]??$log['entity_type']) ?><?= $log['entity_id']!==null?' #'.(int)$log['entity_id']:'' ?><?php else: ?><span class="audit-muted">Not recorded</span><?php endif; ?><?php if($log['affected_user_id']!==null&&$log['entity_type']!=='user'): ?><span class="audit-muted">Account #<?= (int)$log['affected_user_id'] ?></span><?php endif; ?></td>
                        <td><?php if($log['ip_address']): ?><span class="audit-ip"><?= $escape($log['ip_address']) ?></span><?php else: ?><span class="audit-muted">Not recorded</span><?php endif; ?><?php if($log['user_agent']): ?><details class="audit-browser"><summary>Browser details</summary><p><?= $escape($log['user_agent']) ?></p></details><?php endif; ?></td>
                        <td class="audit-details"><?= trim((string)$log['details'])!==''?$escape($log['details']):'<span class="audit-muted">No additional details.</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if(!$result['rows']): ?><tr><td colspan="6" class="audit-empty"><strong><?= $error?'Audit events are unavailable.':'No matching events.' ?></strong><p><?= $error?'Resolve the message above and reload this page.':'Try another date range or reset the filters. Events appear here when supported actions occur.' ?></p></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <footer class="audit-results-footer">
            <p>Times: <?= $escape(date_default_timezone_get()) ?>. Connection details appear only when originally recorded.</p>
            <nav class="audit-pagination" aria-label="Audit event pages">
                <?php if($result['page']>1): ?><a rel="prev" href="<?= $escape($pageLink($result['page']-1)) ?>">&larr; Previous</a><?php endif; ?>
                <span>Page <?= $result['page'] ?> of <?= $result['pages'] ?></span>
                <?php if($result['page']<$result['pages']): ?><a rel="next" href="<?= $escape($pageLink($result['page']+1)) ?>">Next &rarr;</a><?php endif; ?>
            </nav>
        </footer>
    </section>
    <p class="audit-coverage">This view combines existing admin and Student ID registry ledgers. Historical events are not rewritten. It is not a complete login, password-reset or summarization history.</p>
</main>
<?php require __DIR__.'/partials/site-footer.php'; ?>
</body>
</html>
