<?php
/**
* PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
**/
_admin();
$ui->assign('_title', $_L['Reports']);
$ui->assign('_system_menu', 'reports');

$action = $routes['1'];
$admin = Admin::_info();
$ui->assign('_admin', $admin);

if($admin['user_type'] != 'Admin' AND $admin['user_type'] != 'Sales'){
	r2(U."dashboard",'e',$_L['Do_Not_Access']);
}

$mdate = date('Y-m-d');
$mtime = date('H:i:s');
$tdate = date('Y-m-d', strtotime('today - 30 days'));
$firs_day_month = date('Y-m-01');
$this_week_start = date('Y-m-d', strtotime('previous sunday'));
$before_30_days = date('Y-m-d', strtotime('today - 30 days'));
$month_n = date('n');

switch ($action) {
    case 'by-date':
    case 'daily-report':
		$paginator = Paginator::bootstrap('tbl_transactions','recharged_on',$mdate);
        $d = ORM::for_table('tbl_transactions')->where('recharged_on',$mdate)->offset($paginator['startpoint'])->limit($paginator['limit'])->order_by_desc('id')->find_many();
		$dr = ORM::for_table('tbl_transactions')->where('recharged_on',$mdate)->sum('price');

        $ui->assign('d',$d);
		$ui->assign('dr',$dr);
		$ui->assign('mdate',$mdate);
		$ui->assign('mtime',$mtime);
		$ui->assign('paginator',$paginator);
        run_hook('view_daily_reports'); #HOOK
        $ui->display('reports-daily.tpl');
        break;

    case 'trial-users':
        // Free-trial usage: grouped per mobile number (times used + total
        // bandwidth + total minutes), plus the recent individual sessions.
        // Timestamps are written with PHP date() in the app timezone, but MySQL
        // NOW() runs in the DB server timezone (UTC in the prod container), so
        // open sessions must be measured against a PHP-supplied "now". Each
        // session is also clamped to 0..<trial duration> minutes: the router caps
        // a trial at that uptime per MAC, and rows closed late by the cron sweep
        // would otherwise inflate the total.
        $trialCapMin = (int) ($config['hotspot_trial_duration_minutes'] ?? 60);
        if ($trialCapMin < 1) $trialCapMin = 60;
        $grouped = ORM::for_table('tbl_hotspot_trials')->raw_query(
            "SELECT phone,
                    SUBSTRING_INDEX(GROUP_CONCAT(name ORDER BY id DESC SEPARATOR '||'),'||',1) AS name,
                    COUNT(*) AS times,
                    SUM(bytes_in + bytes_out) AS total_bytes,
                    SUM(LEAST(GREATEST(TIMESTAMPDIFF(MINUTE, started_at, COALESCE(ended_at, ?)), 0), ?)) AS total_minutes,
                    MAX(started_at) AS last_used
             FROM tbl_hotspot_trials
             GROUP BY phone
             ORDER BY last_used DESC", [date('Y-m-d H:i:s'), $trialCapMin]
        )->find_many();
        $sessions = ORM::for_table('tbl_hotspot_trials')
            ->order_by_desc('id')->limit(200)->find_many();
        $ui->assign('grouped', $grouped);
        $ui->assign('sessions', $sessions);
        run_hook('view_trial_users'); #HOOK
        $ui->display('reports-trial.tpl');
        break;

    case 'by-period':
		$ui->assign('mdate',$mdate);
		$ui->assign('mtime',$mtime);
		$ui->assign('tdate', $tdate);
        run_hook('view_reports_by_period'); #HOOK
        $ui->display('reports-period.tpl');
        break;

    case 'period-view':
        $fdate = _post('fdate');
        $tdate = _post('tdate');
        $stype = _post('stype');

        $d = ORM::for_table('tbl_transactions');
		if ($stype != ''){
				$d->where('type', $stype);
		}

        $d->where_gte('recharged_on', $fdate);
        $d->where_lte('recharged_on', $tdate);
        $d->order_by_desc('id');
        $x =  $d->find_many();

		$dr = ORM::for_table('tbl_transactions');
		if ($stype != ''){
				$dr->where('type', $stype);
		}

        $dr->where_gte('recharged_on', $fdate);
        $dr->where_lte('recharged_on', $tdate);
		$xy = $dr->sum('price');

		$ui->assign('d',$x);
		$ui->assign('dr',$xy);
        $ui->assign('fdate',$fdate);
        $ui->assign('tdate',$tdate);
        $ui->assign('stype',$stype);
        run_hook('view_reports_period'); #HOOK
        $ui->display('reports-period-view.tpl');
        break;

    default:
        echo 'action not defined';
}