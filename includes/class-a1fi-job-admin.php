<?php
if (!defined('ABSPATH')) { exit; }

/** Admin controls for inspecting and maintaining queued imports. */
class A1FI_Job_Admin {
    public static function boot() {
        add_action('admin_footer', [__CLASS__, 'render_jobs_ui']);
        add_action('wp_ajax_a1fi_process_queue_now', [__CLASS__, 'process_queue_now']);
        add_action('wp_ajax_a1fi_delete_queued_job', [__CLASS__, 'delete_queued_job']);
        add_action('wp_ajax_a1fi_job_status', [__CLASS__, 'job_status']);
    }

    private static function can_manage() {
        return current_user_can('manage_woocommerce');
    }

    public static function job_status() {
        if (!self::can_manage()) wp_send_json_error(__('Unauthorized.', 'allinonefeedimporter'), 403);
        check_ajax_referer('a1fi_job_status');

        $job_id = absint($_POST['job_id'] ?? 0);
        global $wpdb;
        $job = $wpdb->get_row($wpdb->prepare(
            'SELECT id,status,total_rows,processed_rows,created_count,updated_count,skipped_count,failed_count,message,errors FROM ' . A1FI_Queue::jobs_table() . ' WHERE id=%d',
            $job_id
        ));
        if (!$job) wp_send_json_error(__('Import job not found.', 'allinonefeedimporter'), 404);

        $errors = $job->errors ? json_decode($job->errors, true) : [];
        if (!is_array($errors)) $errors = [];
        wp_send_json_success([
            'id' => (int) $job->id,
            'status' => $job->status,
            'total' => (int) $job->total_rows,
            'processed' => (int) $job->processed_rows,
            'created' => (int) $job->created_count,
            'updated' => (int) $job->updated_count,
            'skipped' => (int) $job->skipped_count,
            'failed' => (int) $job->failed_count,
            'message' => (string) ($job->message ?? ''),
            'errors' => array_values(array_filter($errors, 'is_string')),
        ]);
    }

    public static function process_queue_now() {
        if (!self::can_manage()) wp_send_json_error(__('Unauthorized.', 'allinonefeedimporter'), 403);
        check_ajax_referer('a1fi_queue_controls');

        $job_id = absint($_POST['job_id'] ?? 0);
        global $wpdb;
        $job = $wpdb->get_row($wpdb->prepare('SELECT id,status FROM ' . A1FI_Queue::jobs_table() . ' WHERE id=%d', $job_id));
        if (!$job) wp_send_json_error(__('Import job not found.', 'allinonefeedimporter'), 404);
        if (!in_array($job->status, ['queued', 'running'], true)) {
            wp_send_json_success(['status' => $job->status, 'message' => __('This job is no longer waiting to run.', 'allinonefeedimporter')]);
        }

        // The normal worker is cron-triggered. This authenticated admin action provides
        // a fallback when a host has WP-Cron events scheduled but does not dispatch them.
        do_action('a1fi_process_queue');
        $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . A1FI_Queue::jobs_table() . ' WHERE id=%d', $job_id));
        wp_send_json_success(['status' => $status ?: $job->status]);
    }

    public static function delete_queued_job() {
        if (!self::can_manage()) wp_send_json_error(__('Unauthorized.', 'allinonefeedimporter'), 403);
        check_ajax_referer('a1fi_queue_controls');
        $job_id = absint($_POST['job_id'] ?? 0);
        global $wpdb;
        $table = A1FI_Queue::jobs_table();
        $job = $wpdb->get_row($wpdb->prepare("SELECT id,status FROM $table WHERE id=%d", $job_id));
        if (!$job) wp_send_json_error(__('Import job not found.', 'allinonefeedimporter'), 404);
        if ($job->status !== 'queued') wp_send_json_error(__('Only queued jobs can be deleted. Running or completed jobs are retained.', 'allinonefeedimporter'), 409);

        $wpdb->delete(A1FI_Queue::rows_table(), ['job_id' => $job_id], ['%d']);
        $wpdb->delete($table, ['id' => $job_id, 'status' => 'queued'], ['%d', '%s']);
        wp_send_json_success(['deleted' => true]);
    }

    public static function render_jobs_ui() {
        if (!is_admin() || !isset($_GET['page']) || sanitize_key($_GET['page']) !== 'a1fi' || !self::can_manage()) return;
        global $wpdb;
        $jobs_table = A1FI_Queue::jobs_table();
        $jobs = $wpdb->get_results("SELECT id,feed_id,status,total_rows,processed_rows,created_count,updated_count,skipped_count,failed_count,started_at FROM $jobs_table ORDER BY id DESC LIMIT 25");
        $feeds = get_option(A1FI_Plugin::OPTION, []);
        $nonce = wp_create_nonce('a1fi_queue_controls');
        $status_nonce = wp_create_nonce('a1fi_job_status');
        $active_job_id = absint($_GET['a1fi_job'] ?? 0);
        ?>
        <template id="a1fi-jobs-template">
            <section class="a1fi-jobs-panel" style="margin-top:24px">
                <h2><?php esc_html_e('Import jobs', 'allinonefeedimporter'); ?></h2>
                <p><?php esc_html_e('Recent import jobs and their status. The job progress page can run a batch from your browser if the server cron worker is not dispatching.', 'allinonefeedimporter'); ?></p>
                <?php if ($active_job_id): ?>
                    <div class="notice notice-info" id="a1fi-live-progress" data-job-id="<?php echo $active_job_id; ?>" role="status" aria-live="polite">
                        <p class="a1fi-progress-text"><?php printf(esc_html__('Loading progress for job #%d…', 'allinonefeedimporter'), $active_job_id); ?></p>
                        <progress max="100" value="0" style="width:100%;max-width:500px"></progress>
                    </div>
                <?php endif; ?>
                <div class="a1fi-jobs-table-wrap">
                    <table class="widefat striped"><thead><tr>
                        <th><?php esc_html_e('Job', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Feed', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Status', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Progress', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Started', 'allinonefeedimporter'); ?></th><th><?php esc_html_e('Actions', 'allinonefeedimporter'); ?></th>
                    </tr></thead><tbody>
                    <?php if (!$jobs): ?><tr><td colspan="6"><?php esc_html_e('No import jobs found.', 'allinonefeedimporter'); ?></td></tr><?php endif; ?>
                    <?php foreach ($jobs as $job): $active = in_array($job->status, ['queued','running'], true); $feed_name = $feeds[$job->feed_id]['name'] ?? sprintf(__('Feed #%d', 'allinonefeedimporter'), $job->feed_id); ?>
                        <tr data-a1fi-job-row="<?php echo (int)$job->id; ?>">
                            <td>#<?php echo (int)$job->id; ?></td><td><?php echo esc_html($feed_name); ?></td><td class="a1fi-job-status"><?php echo esc_html($job->status); ?></td>
                            <td class="a1fi-job-progress"><span><?php echo (int)$job->processed_rows; ?> / <?php echo (int)$job->total_rows; ?> (<?php echo $job->total_rows ? (int)floor(($job->processed_rows / $job->total_rows) * 100) : ($job->status === 'completed' ? 100 : 0); ?>%)</span><br><small><?php printf(esc_html__('Created %1$d, updated %2$d, skipped %3$d, failed %4$d', 'allinonefeedimporter'), $job->created_count, $job->updated_count, $job->skipped_count, $job->failed_count); ?></small></td>
                            <td><?php echo esc_html($job->started_at); ?></td>
                            <td><a class="button" href="<?php echo esc_url(add_query_arg(['page'=>'a1fi','a1fi_job'=>(int)$job->id], admin_url('admin.php'))); ?>"><?php esc_html_e('View progress', 'allinonefeedimporter'); ?></a>
                                <?php if ($active): ?><button type="button" class="button a1fi-run-queue" data-job-id="<?php echo (int)$job->id; ?>"><?php esc_html_e('Process now', 'allinonefeedimporter'); ?></button><?php endif; ?>
                                <?php if ($job->status === 'queued'): ?><button type="button" class="button-link-delete a1fi-delete-job" data-job-id="<?php echo (int)$job->id; ?>"><?php esc_html_e('Delete', 'allinonefeedimporter'); ?></button><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table>
                </div>
            </section>
        </template>
        <script>
        (function(){const wrap=document.querySelector('.wrap'),template=document.getElementById('a1fi-jobs-template');if(wrap&&template)wrap.appendChild(template.content.cloneNode(true));const nonce=<?php echo wp_json_encode($nonce); ?>;const statusNonce=<?php echo wp_json_encode($status_nonce); ?>;const ajax=typeof ajaxurl==='string'&&ajaxurl?ajaxurl:new URL('/wp-admin/admin-ajax.php',window.location.origin).href;async function post(action,id,requestNonce=nonce){const fd=new FormData();fd.append('action',action);fd.append('job_id',id);fd.append('_ajax_nonce',requestNonce);const r=await fetch(ajax,{method:'POST',body:fd,credentials:'same-origin'});const d=await r.json();if(!d.success)throw new Error(d.data||'Request failed');return d.data;}document.addEventListener('click',async function(e){const run=e.target.closest('.a1fi-run-queue');if(run){run.disabled=true;const old=run.textContent;run.textContent='<?php echo esc_js(__('Processing…','allinonefeedimporter')); ?>';try{await post('a1fi_process_queue_now',run.dataset.jobId);window.location.href=run.closest('tr').querySelector('a.button').href;}catch(err){alert(err.message);run.disabled=false;run.textContent=old;}return;}const del=e.target.closest('.a1fi-delete-job');if(del){if(!window.confirm('<?php echo esc_js(__('Delete this queued import and its pending rows?','allinonefeedimporter')); ?>'))return;del.disabled=true;try{await post('a1fi_delete_queued_job',del.dataset.jobId);del.closest('tr').remove();}catch(err){alert(err.message);del.disabled=false;}}});
        <?php if ($active_job_id): ?>
        const activeId=<?php echo $active_job_id; ?>;let workerStopped=false;async function kickWorker(){try{const result=await post('a1fi_process_queue_now',activeId);if(!['queued','running'].includes(result.status))workerStopped=true;}catch(e){console.error('Import worker:',e);}if(!workerStopped)setTimeout(kickWorker,5000);}kickWorker();
        <?php endif; ?>
        const liveProgress=document.getElementById('a1fi-live-progress');
        const rows=Array.from(document.querySelectorAll('tr[data-a1fi-job-row]'));
        const jobIds=new Set(rows.filter(row=>['queued','running'].includes(row.querySelector('.a1fi-job-status').textContent.trim())).map(row=>row.dataset.a1fiJobRow));
        if(liveProgress)jobIds.add(liveProgress.dataset.jobId);
        const countsTemplate=<?php echo wp_json_encode(__('Created %1$d, updated %2$d, skipped %3$d, failed %4$d', 'allinonefeedimporter')); ?>;
        const statusError=<?php echo wp_json_encode(__('Could not refresh job status:', 'allinonefeedimporter')); ?>;
        const emptyJobWarning=<?php echo wp_json_encode(__('Job completed without processing rows. Refresh the page to retry the import or check the feed mappings and server database error log.', 'allinonefeedimporter')); ?>;
        function counts(job){return countsTemplate.replace('%1$d',job.created).replace('%2$d',job.updated).replace('%3$d',job.skipped).replace('%4$d',job.failed);}
        async function refreshProgress(jobId){
            const row=rows.find(item=>item.dataset.a1fiJobRow===jobId);
            const selected=liveProgress&&liveProgress.dataset.jobId===jobId;
            try{
                const job=await post('a1fi_job_status',jobId,statusNonce);
                const percent=job.total?Math.min(100,Math.floor(job.processed/job.total*100)):(job.status==='completed'?100:0);
                const progress=`${job.processed} / ${job.total} (${percent}%)`;
                if(row){
                    row.querySelector('.a1fi-job-status').textContent=job.status;
                    row.querySelector('.a1fi-job-progress span').textContent=progress;
                    row.querySelector('.a1fi-job-progress small').textContent=counts(job);
                    if(job.status!=='queued'){const del=row.querySelector('.a1fi-delete-job');if(del)del.remove();}
                    if(!['queued','running'].includes(job.status)){const run=row.querySelector('.a1fi-run-queue');if(run)run.remove();}
                }
                if(selected){
                    liveProgress.querySelector('progress').value=percent;
                    const text=liveProgress.querySelector('.a1fi-progress-text');
                    text.textContent=`#${job.id}: ${job.status} — ${progress}. ${counts(job)}`;
                    if(job.message)text.textContent+=' '+job.message;
                    if(job.errors.length)text.textContent+=' '+job.errors.join(' ');
                    if(job.status==='completed'&&job.total>0&&job.processed===0)text.textContent+=' '+emptyJobWarning;
                }
                if(!['queued','running'].includes(job.status))return;
            }catch(error){
                if(selected)liveProgress.querySelector('.a1fi-progress-text').textContent=statusError+' '+error.message;
                else if(row)row.querySelector('.a1fi-job-progress small').textContent=statusError+' '+error.message;
            }
            setTimeout(()=>refreshProgress(jobId),2000);
        }
        jobIds.forEach(refreshProgress);
        })();
        </script>
        <?php
    }
}
