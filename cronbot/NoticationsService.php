<?php
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';

class ServiceMonitor
{
    private $Panel;
    private $pdo;
    private $setting;
    private $reportCron;
    private $text_Purchased_services;
    private $status_cron;
    const SECONDS_PER_DAY = 86400;
    private $textBotLang;

    public function __construct()
    {
        global $pdo;
        $this->pdo = $pdo;
        $this->Panel = new ManagePanel();
        $reportTopic = select("topicid", "idreport", "report", "reportcron", "select");
        $this->reportCron = is_array($reportTopic) ? ($reportTopic['idreport'] ?? null) : null;
        $this->setting = select("setting", "*");
        $this->status_cron = json_decode($this->setting['cron_status'] ?? '{}', true);
        if (!is_array($this->status_cron)) {
            $this->status_cron = [];
        }
        $this->textBotLang = languagechange();
        $this->text_Purchased_services = $this->textBotLang['textbot']['purchasedServices'] ?? '';
    }

    public function RunNotifactions()
    {
        $invoices = $this->getActiveInvoices();
        if ($invoices == false)
            return;
        foreach ($invoices as $invoice) {
            if ($invoice['time_cron'] != null) {
                $time_cron = time() - $invoice['time_cron'];
                if ($time_cron < 1600)
                    continue;
            }
            update("invoice", "time_cron", time(), "id_invoice", $invoice['id_invoice']);
            $check_send = json_decode($invoice['notifctions'] ?? '', true);
            if (!is_array($check_send)) {
                $check_send = ['volume' => false, 'time' => false];
            }
            $data = $this->processInvoice($invoice);
            if (!is_array($data))
                continue;
            $result = false;
            if (empty($check_send['volume'])) {
                if (!empty($this->status_cron['volume']))
                    $result = $this->checkVolumeThreshold($data['invoice'], $data['user'], $data['userData'], $invoice['username']);
            }
            if ($result)
                $data['invoice'] = select("invoice", "*", "id_invoice", $invoice['id_invoice']);
            if (empty($check_send['time'])) {
                if (!empty($this->status_cron['day']))
                    $this->checkTimeExpiration($data['invoice'], $data['user'], $data['userData'], $invoice['username']);
            }
            if (!empty($this->status_cron['remove']))
                $this->shouldRemoveService($data['invoice'], $data['user'], $data['userData'], $invoice['username']);
            if (!empty($this->status_cron['remove_volume']))
                $this->shouldRemoveServiceـvolume($data['invoice'], $data['user'], $data['userData'], $invoice['username']);
            if ($data['panel']['inboundstatus'] == "oninbounddisable" && $data['panel']['type'] == "marzban")
                $this->active_inbound_expire($data['invoice'], $data['userData'], $data['panel']);
        }
    }


    private function getActiveInvoices()
    {
        $time_hours = time() - 3600;
        $QUERY = "SELECT * FROM invoice WHERE (Status = 'active' OR Status = 'end_of_time' OR Status = 'end_of_volume' OR Status = 'sendedwarn' OR Status = 'send_on_hold') AND name_product != '{$this->textBotLang['Admin']['adminphp']['db_test_service_name']}' AND (time_cron <= '$time_hours' OR time_cron IS NULL) ORDER BY time_cron LIMIT 100";
        $stmt = $this->pdo->prepare($QUERY);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function processInvoice($invoice)
    {
        $username = $invoice['username'];

        // Get panel information
        $panelInfo = select("marzban_panel", "*", "name_panel", $invoice['Service_location'], "select");
        if (!$panelInfo)
            return false;

        if ($panelInfo['status'] == "disabled")
            return false;
        // Get user information
        $user = select("user", "*", "id", $invoice['id_user'], "select");
        if ($user == false)
            return false;

        // Get username data from panel
        $userData = $this->Panel->DataUser($invoice['Service_location'], $username);
        if (!$userData || $userData['status'] == "Unsuccessful")
            return;
        return [
            'invoice' => $invoice,
            'panel' => $panelInfo,
            'user' => $user,
            'userData' => $userData
        ];
    }

    private function checkVolumeThreshold($invoice, $user, $userData, $username)
    {
        $dataLimit = $userData['data_limit'];
        // Skip unlimited services (no data cap)
        if (empty($dataLimit) || $dataLimit <= 0) {
            return false;
        }
        $usedTraffic = floatval($userData['used_traffic'] ?? 0);
        $remainingVolume = $dataLimit - $usedTraffic;
        // Warn at 80% used OR any point past 80% (including fully exhausted)
        $usedPercent = ($usedTraffic / $dataLimit) * 100;
        $isVolumeWarning = $usedPercent >= 80 && in_array($userData['status'], ['active', 'Unknown', 'limited']);

        if ($isVolumeWarning) {
            $remaining = max(0, $remainingVolume);
            $formattedVolume = $remaining > 0 ? formatBytes($remaining) : ($this->textBotLang['hardcoded']['notifVolumeExhausted'] ?? 'صفر');
            $message = $this->textBotLang['hardcoded']['notifGreeting'] .
                sprintf($this->textBotLang['hardcoded']['notifServiceName'] ?? "🔖 نام کاربری سرویس: <code>%s</code>\n", htmlspecialchars($username)) .
                sprintf($this->textBotLang['hardcoded']['notifVolumeRemaining'], $username, $formattedVolume) .
                sprintf($this->textBotLang['hardcoded']['notifVolumeActionHint'], $this->text_Purchased_services) .
                "\n\n" . ($this->textBotLang['hardcoded']['notif48hDeleteWarning'] ?? '') .
                "\n" . $this->textBotLang['hardcoded']['notifThanks'];
            $reportMessage = $this->textBotLang['hardcoded']['notifVolumeCronTitle'] .
                sprintf($this->textBotLang['hardcoded']['notifServiceUsername'], $username) .
                sprintf($this->textBotLang['hardcoded']['notifServiceStatus'], $userData['status']) .
                sprintf($this->textBotLang['hardcoded']['notifRemainingVolume'], $formattedVolume);
            $sent = $this->send_notifactions($invoice, $user, $message, true, $invoice['bottype']);
            if ($sent) {
                $this->sendReportNotification($reportMessage);
                $this->updateInvoiceStatus("volume", $invoice);
                return true;
            }
            return false;
        }
        return false;
    }
    private function shouldRemoveService($invoice, $user, $userData, $username)
    {
        if (!in_array($userData['status'], ['limited', 'expired']))
            return false;
        $timeService = $userData['expire'] - time();
        $daysRemaining = intval($timeService / 86400);
        $removalThreshold = intval("-" . $this->setting['removedayc']);
        $result = $daysRemaining <= $removalThreshold;
        $statusText = $statusMap = [
            'active' => $this->textBotLang['users']['stateus']['active'],
            'limited' => $this->textBotLang['users']['stateus']['limited'],
            'disabled' => $this->textBotLang['users']['stateus']['disabled'],
            'expired' => $this->textBotLang['users']['stateus']['expired'],
            'on_hold' => $this->textBotLang['users']['stateus']['on_hold'],
            'Unknown' => $this->textBotLang['users']['stateus']['Unknown']
        ][$userData['status']];
        $remainingVolume = formatBytes($userData['data_limit'] - $userData['used_traffic']);
        if ($result) {
            update("invoice", "status", "removeTime", "username", $username);
            $this->Panel->RemoveUser($invoice['Service_location'], $username);
            $message = sprintf($this->textBotLang['hardcoded']['notifServiceDeleted'], $invoice['username']);
            $reportMessage = sprintf($this->textBotLang['hardcoded']['notifDeleteCronInfo'], $invoice['username'], $statusText, $daysRemaining, $remainingVolume);
            $this->send_notifactions($invoice, $user, $message, false, $invoice['bottype']);
            $this->sendReportNotification($reportMessage);
        }
    }
    private function shouldRemoveServiceـvolume($invoice, $user, $userData, $username)
    {
        if (!in_array($userData['status'], ['limited', 'expired']))
            return false;
        $panel = select("marzban_panel", "*", "name_panel", $invoice['Service_location'], "select");
        if ($panel['type'] != "marzban")
            return;
        if ($userData['data_limit_reset'] != "no_reset")
            return;
        if ($userData['status'] == "Unsuccessful")
            return;
        if (in_array($userData['status'], ['Unknown', 'active', 'on_hold', 'disabled', 'expired']))
            return;
        if (empty($userData['online_at']) or $userData['online_at'] == null) {
            $timelastconect = 0;
        } else {
            $time = strtotime($userData['online_at']);
            $timelastconect = (time() - $time) / 86400;
        }
        if ($timelastconect == 0)
            return;
        $timeService = $userData['expire'] - time();
        $daysRemaining = intval($timeService / 86400);
        $removalThreshold = intval($this->setting['cronvolumere']);
        $result = $timelastconect >= $removalThreshold;
        $statusText = [
            'active' => $this->textBotLang['users']['stateus']['active'],
            'limited' => $this->textBotLang['users']['stateus']['limited'],
            'disabled' => $this->textBotLang['users']['stateus']['disabled'],
            'expired' => $this->textBotLang['users']['stateus']['expired'],
            'on_hold' => $this->textBotLang['users']['stateus']['on_hold'],
            'Unknown' => $this->textBotLang['users']['stateus']['Unknown']
        ][$userData['status']];
        $remainingVolume = formatBytes($userData['data_limit'] - $userData['used_traffic']);
        if ($result) {
            update("invoice", "status", "removevolume", "username", $username);
            $this->Panel->RemoveUser($invoice['Service_location'], $username);
            $message = sprintf($this->textBotLang['hardcoded']['notifServiceDeleted2'], $username);
            $reportMessage = sprintf($this->textBotLang['hardcoded']['notifVolumeDeleteCronInfo'], $username, $statusText, $daysRemaining, $remainingVolume, $userData['online_at']);
            $this->send_notifactions($invoice, $user, $message, false, $invoice['bottype']);
            $this->sendReportNotification($reportMessage);
        }
    }
    private function active_inbound_expire($invoice, $userData, $panel_info)
    {
        if ($invoice['uuid'] != null || $userData['data_limit_reset'] != "no_reset")
            return;
        $inbound = explode("*", $panel_info['inbound_deactive']);
        update("invoice", "uuid", json_encode($userData['uuid']), "id_invoice", $invoice['id_invoice']);
        $proxies = [];
        $proxies[$inbound[0]] = new stdClass();
        ;
        $inbounds[$inbound[0]][] = $inbound[1];
        $configs = array(
            "proxies" => $proxies,
            "inbounds" => $inbounds
        );
        $this->Panel->Modifyuser($invoice['username'], $panel_info['code_panel'], $configs);
    }
    private function checkTimeExpiration($invoice, $user, $userData, $username)
    {
        // Skip on-hold / unlimited-time services
        if (($userData['status'] ?? '') === 'on_hold')
            return false;
        $expire = intval($userData['expire'] ?? 0);
        if ($expire <= 0)
            return false;

        $totalSeconds = $this->resolveServiceTotalSeconds($invoice, $expire);
        if ($totalSeconds <= 0)
            return false;

        $timeRemaining = $expire - time();
        $daysRemaining = max(0, intval($timeRemaining / self::SECONDS_PER_DAY));
        // Warn at 80% of total duration used OR any point past 80% (including fully expired)
        $elapsed = $totalSeconds - $timeRemaining;
        $usedPercent = ($elapsed / $totalSeconds) * 100;
        $isTimeWarning = $usedPercent >= 80 && in_array($userData['status'], ['active', 'Unknown', 'limited', 'expired']);

        if ($isTimeWarning) {
            $message = $this->textBotLang['hardcoded']['notifGreeting2'] .
                sprintf($this->textBotLang['hardcoded']['notifServiceName'] ?? "🔖 نام کاربری سرویس: <code>%s</code>\n", htmlspecialchars($username)) .
                sprintf($this->textBotLang['hardcoded']['notifTimeRemaining'], $username, $daysRemaining) .
                sprintf($this->textBotLang['hardcoded']['notifTimeActionHint'], $this->text_Purchased_services) .
                "\n\n" . ($this->textBotLang['hardcoded']['notif48hDeleteWarning'] ?? '') .
                "\n" . $this->textBotLang['hardcoded']['notifThanks'];
            $reportMessage = $this->textBotLang['hardcoded']['notifTimeCronTitle'] .
                sprintf($this->textBotLang['hardcoded']['notifServiceUsername2'], $invoice['username']) .
                sprintf($this->textBotLang['hardcoded']['notifServiceStatus2'], $userData['status']) .
                sprintf($this->textBotLang['hardcoded']['notifRemainingDays'], $daysRemaining);
            $sent = $this->send_notifactions($invoice, $user, $message, true, $invoice['bottype']);
            if ($sent) {
                $this->sendReportNotification($reportMessage);
                $this->updateInvoiceStatus("time", $invoice);
                return true;
            }
            return false;
        }
        return false;
    }

    /**
     * Total purchased duration in seconds (for 80% time warnings).
     * Prefers invoice Service_time; falls back to expire - time_sell.
     */
    private function resolveServiceTotalSeconds($invoice, $expire)
    {
        $serviceDays = intval($invoice['Service_time'] ?? 0);
        if ($serviceDays > 0) {
            return $serviceDays * self::SECONDS_PER_DAY;
        }
        $soldAt = intval($invoice['time_sell'] ?? 0);
        if ($soldAt > 0 && $expire > $soldAt) {
            return $expire - $soldAt;
        }
        return 0;
    }

    private function send_notifactions($invoice, $user, $message, $keyboard_active, $bot_token)
    {
        if (intval($user['status_cron'] ?? 1) == 0)
            return false;
        $keyboard = $keyboard_active ? $this->createExtendServiceKeyboard($invoice['id_invoice']) : null;
        // Empty bottype must fall back to main bot token (null), not ""
        $token = (isset($bot_token) && $bot_token !== '') ? $bot_token : null;
        $result = sendmessage($invoice['id_user'], $message, $keyboard, 'HTML', $token);
        return is_array($result) && !empty($result['ok']);
    }

    private function createExtendServiceKeyboard($invoiceId)
    {
        // Must use $this->textBotLang — global $textbotlang is only set in webhook (index.php), not in cron
        $btnText = $this->textBotLang['keyboard']['renewService'] ?? '💊 تمدید سرویس';
        return json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $btnText, 'callback_data' => 'extend_' . $invoiceId],
                ],
            ]
        ]);
    }

    private function sendReportNotification($reportMessage)
    {
        if (empty($this->setting['Channel_Report']))
            return;

        $payload = [
            'chat_id' => $this->setting['Channel_Report'],
            'text' => $reportMessage,
            'parse_mode' => "HTML"
        ];
        if (!empty($this->reportCron) && intval($this->reportCron) > 0) {
            $payload['message_thread_id'] = $this->reportCron;
        }
        telegram('sendmessage', $payload);
    }

    private function updateInvoiceStatus($type, $invoice)
    {
        $data = json_decode($invoice['notifctions'] ?? '', true);
        if (!is_array($data)) {
            $data = ['volume' => false, 'time' => false];
        }
        $data[$type] = true;
        $data = json_encode($data);
        update("invoice", "notifctions", $data, "id_invoice", $invoice['id_invoice']);
    }
}

// Execute the volume monitoring
$volumeMonitor = new ServiceMonitor();
$volumeMonitor->RunNotifactions();
