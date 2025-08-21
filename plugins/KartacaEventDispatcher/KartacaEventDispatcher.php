<?php

use Google\Cloud\PubSub\PubSubClient;

class KartacaEventDispatcher extends PluginBase
{
    protected $storage = 'DbStorage';
    static protected $name = 'KartacaEventDispatcher';
    static protected $description = 'Dispatch survey events to Webhook and/or Google Pub/Sub';

    private $pubsubClient;
    private $answerIndex = [];
    private $subqCache = [];

    public function init()
    {
        date_default_timezone_set('Europe/Istanbul');
        set_error_handler(function ($errno, $errstr, $errfile, $errline) { error_log("PHP Error [$errno]: $errstr in $errfile on $errline"); });
        $this->subscribe('beforeSurveyPage');
        $this->subscribe('beforeControllerAction');
        $this->subscribe('afterSurveyComplete');
        try { $this->ensureSessionTable(); } catch (Throwable $e) { $this->logEvent('[WARN] ensureSessionTable in init: '.$e->getMessage()); }
    }

    public function getPluginSettings($getValues = true)
    {
        return [
            'enableWebhook' => [
                'type' => 'boolean', 'label' => 'Enable Webhook',
                'default' => true, 'current' => $this->get('enableWebhook', null, null, true),
            ],
            'webhookUrl' => [
                'type' => 'string', 'label' => 'Webhook URL',
                'help' => 'E.g., https://example.com/hook',
                'default' => '', 'current' => $this->get('webhookUrl'),
            ],
            'authHeaderKey' => [
                'type' => 'string', 'label' => 'Auth Header Key',
                'default' => '', 'current' => $this->get('authHeaderKey'),
            ],
            'authHeaderValue' => [
                'type' => 'string', 'label' => 'Auth Header Value',
                'default' => '', 'current' => $this->get('authHeaderValue'),
            ],
            'enablePubSub' => [
                'type' => 'boolean', 'label' => 'Enable Google Pub/Sub',
                'default' => false, 'current' => $this->get('enablePubSub', null, null, false),
            ],
            'gcpProjectId' => [
                'type' => 'string', 'label' => 'GCP Project ID',
                'default' => '', 'current' => $this->get('gcpProjectId'),
            ],
            'pubsubTopic' => [
                'type' => 'string', 'label' => 'Pub/Sub Topic',
                'default' => 'survey-events', 'current' => $this->get('pubsubTopic', null, null, 'survey-events'),
            ],
            'gcpCredentialsPath' => [
                'type' => 'string', 'label' => 'Credentials JSON Path',
                'default' => '', 'current' => $this->get('gcpCredentialsPath'),
            ],
            'abandonThresholdMinutes' => [
                'type' => 'int', 'label' => 'Abandon threshold (minutes)',
                'help'  => 'No heartbeat/page activity within this many minutes AND not completed ⇒ send session_abandoned.',
                'default' => 3, 'current' => (int)$this->get('abandonThresholdMinutes', null, null, 3),
            ],
            'selfScanEverySeconds' => [
                'type' => 'int', 'label' => 'Self-scan interval (seconds)',
                'help'  => 'Abandonment scan is performed at most this frequently per incoming request.',
                'default' => 60, 'current' => (int)$this->get('selfScanEverySeconds', null, null, 60),
            ],
            'enableEventLogging' => [
                'type' => 'boolean', 'label' => 'Event Logging Active',
                'default' => true, 'current' => $this->get('enableEventLogging', null, null, true),
            ],
            'enableJsonLogging' => [
                'type' => 'boolean', 'label' => 'JSON Logging Active',
                'default' => true, 'current' => $this->get('enableJsonLogging', null, null, true),
            ],
        ];
    }

    private function getLogDir(): string { return dirname(__FILE__) . '/logs'; }
    private function getLogPath(): string { return $this->getLogDir() . '/kartaca-event.log'; }
    private function getJsonLogPath(): string { return $this->getLogDir() . '/kartaca-data.ndjson'; }
    private function ensureLogDirectory(string $filePath): void { $dir = dirname($filePath); if (!is_dir($dir)) { @mkdir($dir, 0777, true); } }
    private function logEvent(string $text): void { if ($this->get('enableEventLogging', null, null, true)) { $this->ensureLogDirectory($this->getLogPath()); @file_put_contents($this->getLogPath(), '['.date('Y-m-d H:i:s')."] $text\n", FILE_APPEND | LOCK_EX); } }
    private function logJson(array $data): void { if ($this->get('enableJsonLogging', null, null, true)) { $this->ensureLogDirectory($this->getJsonLogPath()); @file_put_contents($this->getJsonLogPath(), json_encode($data, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX); } }

    private function sendChannels(array $payload): void
    {
        $sent = false;
        if ((bool)$this->get('enableWebhook', null, null, true)) { $this->sendWebhook($payload); $sent = true; }
        if ((bool)$this->get('enablePubSub', null, null, false)) { $this->sendPubSub($payload); $sent = true; }
        if (!$sent) $this->logEvent('[WARN] No channel enabled for '.($payload['event'] ?? 'event'));
        $this->logJson($payload);
    }

    private function sendWebhook(array $payload): void
    {
        $webhookUrl = $this->get('webhookUrl', null, null, '');
        if (!$webhookUrl) { $this->logEvent('Webhook URL not configured, skipping: '.($payload['event'] ?? 'unknown')); return; }
        $headers = ['Content-Type: application/json'];
        $authKey = $this->get('authHeaderKey', null, null, '');
        $authValue = $this->get('authHeaderValue', null, null, '');
        if ($authKey && $authValue) $headers[] = "{$authKey}: {$authValue}";
        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) $this->logEvent('Webhook FAILED: '.($payload['event'] ?? 'unknown').' - cURL Error: '.$error);
        elseif ($httpCode >= 200 && $httpCode < 300) $this->logEvent('Webhook SUCCESS: '.($payload['event'] ?? 'unknown')." - HTTP $httpCode");
        else $this->logEvent('Webhook FAILED: '.($payload['event'] ?? 'unknown')." - HTTP $httpCode - Response: $response");
    }

    private function getPubSubClient()
    {
        if ($this->pubsubClient) return $this->pubsubClient;
        if (!(bool)$this->get('enablePubSub', null, null, false)) return null;
        $projectId = trim((string)$this->get('gcpProjectId'));
        if (!$projectId) return null;
        $credsPath = trim((string)$this->get('gcpCredentialsPath'));
        try {
            $opts = ['projectId' => $projectId];
            if ($credsPath) $opts['keyFilePath'] = $credsPath;
            $this->pubsubClient = new PubSubClient($opts);
            return $this->pubsubClient;
        } catch (Throwable $e) {
            $this->logEvent('[PUBSUB][ERROR] Client init: '.$e->getMessage());
            return null;
        }
    }

    private function buildPubSubAttributes(array $p): array
    {
        $a = [];
        if (isset($p['event']))       $a['event'] = (string)$p['event'];
        if (isset($p['survey_id']))   $a['survey_id'] = (string)$p['survey_id'];
        if (isset($p['response_id'])) $a['response_id'] = (string)$p['response_id'];
        if (isset($p['step']))        $a['step'] = (string)$p['step'];
        if (isset($p['step_submitted'])) $a['step_submitted'] = (string)$p['step_submitted'];
        if (isset($p['uuid']))        $a['uuid'] = (string)$p['uuid'];
        if (isset($p['custom_id']))   $a['custom_id'] = (string)$p['custom_id'];
        $a['source'] = 'KartacaEventDispatcher';
        $a['schema'] = 'v1';
        return $a;
    }

    private function sendPubSub(array $payload): void
    {
        $client = $this->getPubSubClient();
        if (!$client) { $this->logEvent('[PUBSUB] Client not ready'); return; }
        $topicName = trim((string)$this->get('pubsubTopic', null, null, 'survey-events'));
        try {
            $client->topic($topicName)->publish([
                'data' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'attributes' => $this->buildPubSubAttributes($payload),
            ]);
            $this->logEvent('PubSub SUCCESS: '.($payload['event'] ?? 'unknown'));
        } catch (Throwable $e) {
            $this->logEvent('[PUBSUB][ERROR] '.($payload['event'] ?? 'unknown').' '.$e->getMessage());
        }
    }

    private function getResponseIdFromSession($surveyId)
    {
        return $_SESSION['survey_' . $surveyId]['srid'] ?? $_SESSION['survey_' . $surveyId]['id'] ?? null;
    }

    private function getQuestionText($qid)
    {
        $questionModel = Question::model()->findByPk($qid);
        if ($questionModel && !empty($questionModel->question)) return strip_tags(trim($questionModel->question));
        $lang = Yii::app()->language;
        $l10nModel = QuestionL10n::model()->find('qid = :qid AND language = :lang', [':qid' => $qid, ':lang' => $lang]);
        if ($l10nModel && !empty($l10nModel->question)) return strip_tags(trim($l10nModel->question));
        $l10nAny = QuestionL10n::model()->find('qid = :qid', [':qid' => $qid]);
        if ($l10nAny && !empty($l10nAny->question)) return strip_tags(trim($l10nAny->question));
        $this->logEvent("[WARN] getQuestionText: not found QID=$qid lang=$lang");
        return '[Question not found]';
    }

    private function ensureSessionTable(): void
    {
        $db = Yii::app()->db;
        $table = $db->schema->getTable('kartaca_webhook_sessions', true);
        if ($table) {
            try {
                Yii::app()->db->createCommand("ALTER TABLE public.kartaca_webhook_sessions ADD COLUMN IF NOT EXISTS php_sid VARCHAR(191) NULL")->execute();
            } catch (Throwable $e) { $this->logEvent('[WARN] add php_sid: '.$e->getMessage()); }
            return;
        }
        $sql = "
            CREATE TABLE IF NOT EXISTS public.kartaca_webhook_sessions (
                id                SERIAL PRIMARY KEY,
                session_id        VARCHAR(191) NOT NULL,
                survey_id         INTEGER NOT NULL,
                response_id       INTEGER NULL,
                step              INTEGER NULL,
                started_at        TIMESTAMP NULL,
                last_seen         TIMESTAMP NOT NULL,
                completed         BOOLEAN NOT NULL DEFAULT FALSE,
                abandoned_sent    BOOLEAN NOT NULL DEFAULT FALSE,
                abandoned_sent_at TIMESTAMP NULL,
                uuid              VARCHAR(64) NULL,
                custom_id         VARCHAR(128) NULL,
                php_sid           VARCHAR(191) NULL,
                CONSTRAINT uniq_session UNIQUE (session_id)
            );
        ";
        $db->createCommand($sql)->execute();
    }

    private function computeSessionId($surveyId, $unused = null): string
    {
        return 'survey_' . $surveyId . '_' . session_id();
    }

    private function upsertActiveSession(int $surveyId, $responseId, $step = null): void
    {
        $this->ensureSessionTable();
        $db = Yii::app()->db;
        $phpSid = session_id();
        $sid = $this->computeSessionId($surveyId);
        $now = date('Y-m-d H:i:s');
        $uuidKey = "kartaca_uuid_$surveyId";
        $customKey = "kartaca_custom_id_$surveyId";
        $uuid = $_SESSION[$uuidKey] ?? $_SESSION['kartaca_uuid'] ?? null;
        $custom = $_SESSION[$customKey] ?? $_SESSION['kartaca_custom_id'] ?? null;
        $existingAbandoned = $db->createCommand("SELECT abandoned_sent FROM public.kartaca_webhook_sessions WHERE session_id=:sid")->bindParam(':sid', $sid)->queryRow();
        if ($existingAbandoned && !empty($existingAbandoned['abandoned_sent'])) { $this->logEvent("[INFO] Heartbeat ignored for abandoned session: $sid"); return; }
        $row = $db->createCommand("SELECT id, step, php_sid FROM public.kartaca_webhook_sessions WHERE session_id=:sid")->bindParam(':sid', $sid)->queryRow();
        if ($row) {
            if ($step !== null) {
                $newStep = is_numeric($row['step']) ? max((int)$row['step'], (int)$step) : (int)$step;
                $db->createCommand("UPDATE public.kartaca_webhook_sessions SET last_seen=:ls, step=:stp, response_id=:rid, uuid=:uuid, custom_id=:cid, php_sid = COALESCE(php_sid, :phpsid) WHERE id=:id")
                   ->bindValues([':ls'=>$now, ':stp'=>$newStep, ':rid'=>$responseId, ':uuid'=>$uuid, ':cid'=>$custom, ':phpsid'=>$phpSid, ':id'=>$row['id']])->execute();
            } else {
                $db->createCommand("UPDATE public.kartaca_webhook_sessions SET last_seen=:ls, response_id=:rid, uuid=:uuid, custom_id=:cid, php_sid = COALESCE(php_sid, :phpsid) WHERE id=:id")
                   ->bindValues([':ls'=>$now, ':rid'=>$responseId, ':uuid'=>$uuid, ':cid'=>$custom, ':phpsid'=>$phpSid, ':id'=>$row['id']])->execute();
            }
        } else {
            $db->createCommand("INSERT INTO public.kartaca_webhook_sessions (session_id, survey_id, response_id, step, started_at, last_seen, uuid, custom_id, php_sid) VALUES (:sid, :sur, :rid, :stp, :sta, :ls, :uuid, :cid, :phpsid)")
               ->bindValues([':sid'=>$sid, ':sur'=>$surveyId, ':rid'=>$responseId, ':stp'=>$step, ':sta'=>$now, ':ls'=>$now, ':uuid'=>$uuid, ':cid'=>$custom, ':phpsid'=>$phpSid])->execute();
        }
        try {
            $db->createCommand("DELETE FROM public.kartaca_webhook_sessions WHERE session_id <> :sid AND survey_id = :sur AND COALESCE(php_sid, '') = :phpsid AND completed = FALSE AND abandoned_sent = FALSE")
               ->bindValues([':sid'=>$sid, ':sur'=>$surveyId, ':phpsid'=>$phpSid])->execute();
        } catch (Throwable $e) { $this->logEvent('[WARN] cleanup duplicates: '.$e->getMessage()); }
    }

    private function markCompletedInStore(int $surveyId, $responseId): void
    {
        $this->ensureSessionTable();
        $db = Yii::app()->db;
        $sid = $this->computeSessionId($surveyId);
        $now = date('Y-m-d H:i:s');
        $db->createCommand("UPDATE public.kartaca_webhook_sessions SET completed = TRUE, last_seen = :ls WHERE session_id = :sid")
           ->bindValues([':ls'=>$now, ':sid'=>$sid])->execute();
    }

    public function runCronCheck(): array
    {
        $this->ensureSessionTable();
        $db = Yii::app()->db;
        $mins = max(1, (int)$this->get('abandonThresholdMinutes', null, null, 3));
        $cutoff = date('Y-m-d H:i:s', time() - $mins * 60);
        $rows = $db->createCommand("
            SELECT * FROM (
                SELECT s.*, ROW_NUMBER() OVER (
                    PARTITION BY s.survey_id, COALESCE(s.php_sid, s.session_id)
                    ORDER BY (s.response_id IS NOT NULL) DESC, COALESCE(s.step,0) DESC, s.last_seen DESC, s.id DESC
                ) AS rn
                FROM public.kartaca_webhook_sessions s
                WHERE s.completed = FALSE
                  AND s.abandoned_sent = FALSE
                  AND s.last_seen < :cutoff
                  AND COALESCE(s.step,0) > 0
            ) x
            WHERE x.rn = 1
        ")->bindParam(':cutoff', $cutoff)->queryAll();
        $sent = 0;
        foreach ($rows as $r) {
            $affected = $db->createCommand("UPDATE public.kartaca_webhook_sessions SET abandoned_sent = TRUE, abandoned_sent_at = :ts WHERE id = :id AND abandoned_sent = FALSE")
                           ->bindValues([':ts' => date('Y-m-d H:i:s'), ':id' => $r['id']])->execute();
            if ($affected !== 1) { continue; }
            $db->createCommand("
                UPDATE public.kartaca_webhook_sessions
                SET abandoned_sent = TRUE, abandoned_sent_at = :ts
                WHERE survey_id = :sur AND id <> :id AND abandoned_sent = FALSE
                  AND (( :phpsid IS NOT NULL AND php_sid = :phpsid ) OR ( :rid IS NOT NULL AND response_id = :rid ) OR ( session_id = :sidExact ))
            ")->bindValues([
                ':ts'=>date('Y-m-d H:i:s'), ':sur'=>(int)$r['survey_id'], ':id'=>$r['id'],
                ':phpsid'=>$r['php_sid'] ?? null, ':rid'=>$r['response_id'] ?? null, ':sidExact'=>$r['session_id'],
            ])->execute();
            $payload = array_merge(
                $this->getCommonPayloadFields((int)$r['survey_id'], $r['response_id'], 'session_abandoned'),
                [
                    'session_id'     => 'survey_' . (int)$r['survey_id'] . '_' . ( $r['php_sid'] ?? preg_replace('/^survey_\d+_/', '', (string)$r['session_id']) ),
                    'event'          => 'session_abandoned',
                    'timestamp'      => date('Y-m-d H:i:s'),
                    'survey_id'      => (int)$r['survey_id'],
                    'response_id'    => $r['response_id'],
                    'last_step'      => is_numeric($r['step']) ? (int)$r['step'] : null,
                    'started_at'     => $r['started_at'],
                    'last_seen_at'   => $r['last_seen'],
                    'inactivity_min' => $mins
                ]
            );
            $this->sendChannels($payload);
            $this->killPhpSessionById($r['php_sid'] ?? null);
            $sent++;
        }
        return ['considered' => count($rows), 'sent' => $sent, 'cutoff' => $cutoff];
    }

    private function maybeRunAbandonScan(): void
    {
        try {
            $interval = max(15, (int)$this->get('selfScanEverySeconds', null, null, 60));
            $lockFile = $this->getLogDir() . '/kartaca-selfscan.lock';
            $now = time();
            if (file_exists($lockFile)) {
                $age = $now - @filemtime($lockFile);
                if ($age < $interval) { return; }
            } else {
                $this->ensureLogDirectory($lockFile);
            }
            $fp = @fopen($lockFile, 'c+');
            if (!$fp) { return; }
            if (!@flock($fp, LOCK_EX | LOCK_NB)) { @fclose($fp); return; }
            @ftruncate($fp, 0);
            @fwrite($fp, (string)$now);
            @fflush($fp);
            @touch($lockFile, $now);
            $res = $this->runCronCheck();
            $this->logEvent('[SELFSCAN] ran: '.json_encode($res));
            @flock($fp, LOCK_UN);
            @fclose($fp);
        } catch (Throwable $e) {
            $this->logEvent('[SELFSCAN][ERROR] '.$e->getMessage());
        }
    }

    private function getCommonPayloadFields($surveyId, $responseId, $event): array
    {
        $uuidKey = "kartaca_uuid_$surveyId";
        $customKey = "kartaca_custom_id_$surveyId";
        $fields = [
            'session_id' => $this->computeSessionId($surveyId),
            'event_time' => time(),
        ];
        if (isset($_SESSION['survey_' . $surveyId]['starttime'])) $fields['session_start_date'] = $_SESSION['survey_' . $surveyId]['starttime'];
        if ($event === 'survey_completed') $fields['session_end_date'] = date('Y-m-d H:i:s');
        if (isset($_SESSION[$uuidKey])) $fields['uuid'] = $_SESSION[$uuidKey];
        elseif (isset($_SESSION['kartaca_uuid'])) $fields['uuid'] = $_SESSION['kartaca_uuid'];
        if (isset($_SESSION[$customKey])) $fields['custom_id'] = $_SESSION[$customKey];
        elseif (isset($_SESSION['kartaca_custom_id'])) $fields['custom_id'] = $_SESSION['kartaca_custom_id'];
        return $fields;
    }

    private function enforceAbandonedGuard(int $surveyId): void
    {
        try {
            $db = Yii::app()->db;
            $phpSid = session_id();
            $row = $db->createCommand("
                SELECT abandoned_sent FROM public.kartaca_webhook_sessions
                WHERE php_sid = :phpsid AND survey_id = :sid
                ORDER BY id DESC LIMIT 1
            ")->bindValues([':phpsid'=>$phpSid, ':sid'=>$surveyId])->queryRow();
            if ($row && !empty($row['abandoned_sent'])) {
                @session_regenerate_id(true);
                unset($_SESSION['survey_' . $surveyId]);
                unset($_SESSION['kartaca_started_sent_' . $surveyId]);
                unset($_SESSION['kartaca_completed']);
                $uuidKey = "kartaca_uuid_$surveyId";
                $customKey = "kartaca_custom_id_$surveyId";
                unset($_SESSION[$uuidKey], $_SESSION[$customKey]);
                unset($_SESSION['kartaca_uuid'], $_SESSION['kartaca_custom_id']);
                $this->logEvent("[GUARD] Abandoned→fresh start enforced for survey_$surveyId");
            }
        } catch (Throwable $e) {
            $this->logEvent("[GUARD][ERROR] ".$e->getMessage());
        }
    }

    public function beforeSurveyPage()
    {
        $this->maybeRunAbandonScan();
        $event = $this->getEvent();
        $surveyId = (int)$event->get('surveyId');
        $this->enforceAbandonedGuard($surveyId);
        $uuidKey = "kartaca_uuid_$surveyId";
        $customKey = "kartaca_custom_id_$surveyId";
        if (isset($_GET['uuid'])) $_SESSION[$uuidKey] = $_GET['uuid'];
        if (isset($_GET['custom_id'])) $_SESSION[$customKey] = $_GET['custom_id'];
        $responseId = $this->getResponseIdFromSession($surveyId);
        $rawStep = $_SESSION['survey_' . $surveyId]['step'] ?? null;
        $stepInt = is_numeric($rawStep) ? (int)$rawStep : null;
        if (empty($_SESSION['survey_' . $surveyId]['starttime'])) {
            $_SESSION['survey_' . $surveyId]['starttime'] = date('Y-m-d H:i:s');
            unset($_SESSION['kartaca_started_sent_' . $surveyId]);
            $this->logEvent("Starttime set manually for survey_$surveyId at " . $_SESSION['survey_' . $surveyId]['starttime']);
        }
        $isFreshStart = empty($_SESSION['kartaca_started_sent_' . $surveyId]) && ($stepInt === null || $stepInt <= 1);
        if ($isFreshStart) {
            if (!isset($_GET['uuid'])) unset($_SESSION[$uuidKey]);
            if (!isset($_GET['custom_id'])) unset($_SESSION[$customKey]);
            if (!isset($_GET['uuid'])) unset($_SESSION['kartaca_uuid']);
            if (!isset($_GET['custom_id'])) unset($_SESSION['kartaca_custom_id']);
            $stampKey = 'kartaca_started_sent_at_'.$surveyId;
            $nowTs = time();
            $lastTs = $_SESSION[$stampKey] ?? 0;
            if (($nowTs - (int)$lastTs) >= 2) {
                $payload = array_merge(
                    $this->getCommonPayloadFields($surveyId, $responseId, 'survey_started'),
                    [
                        'event'       => 'survey_started',
                        'timestamp'   => date('Y-m-d H:i:s'),
                        'survey_id'   => $surveyId,
                        'response_id' => $responseId,
                        'step'        => ($stepInt !== null ? $stepInt : 0),
                    ]
                );
                $this->sendChannels($payload);
                $this->logEvent("Survey started (" . ($stepInt !== null ? "step=$stepInt" : "welcome") . ")");
                $_SESSION['kartaca_started_sent_' . $surveyId] = 1;
                $_SESSION[$stampKey] = $nowTs;
            } else {
                $this->logEvent("[INFO] survey_started debounced");
            }
        }
        $this->upsertActiveSession($surveyId, $responseId, $stepInt !== null ? $stepInt : 0);
    }

    public function beforeControllerAction()
    {
        $this->maybeRunAbandonScan();
        $event = $this->getEvent();
        $controller = $event->get('controller');
        $action = $event->get('action');
        if ($controller === 'survey' && $action === 'index' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $surveyId = $_POST['sid'] ?? null;
            if (!$surveyId) return;
            $this->enforceAbandonedGuard((int)$surveyId);
            $step = $_SESSION['survey_' . $surveyId]['step'] ?? null;
            $responseId = $this->getResponseIdFromSession($surveyId);
            if (!is_numeric($step) || $step < 1) return;
            $previousStep = $step - 1;
            $answers = $this->extractAnswersFromPostQidAid($surveyId, $previousStep, $_POST);
            if (!empty($answers)) {
                $submitPayload = array_merge(
                    $this->getCommonPayloadFields($surveyId, $responseId, 'page_submit'),
                    [
                        'event' => 'page_submit',
                        'timestamp' => date('Y-m-d H:i:s'),
                        'survey_id' => (int)$surveyId,
                        'response_id' => $responseId,
                        'step_submitted' => $previousStep,
                        'answers' => $answers,
                    ]
                );
                $this->sendChannels($submitPayload);
                $this->logEvent("[INFO] Step $step entered → Previous step ($previousStep) answers sent from POST (beforeControllerAction).");
            } else {
                $this->logEvent("[INFO] Step $step entered → No answers found in POST for previous step (beforeControllerAction).");
            }
            $this->upsertActiveSession((int)$surveyId, $responseId, (int)$previousStep);
        }
    }

    public function afterSurveyComplete()
    {
        try {
            $this->maybeRunAbandonScan();
            $event = $this->getEvent();
            $surveyId = (int)$event->get('surveyId');
            $responseId = $this->getResponseIdFromSession($surveyId);
            $_SESSION['kartaca_completed'] = true;
            $this->markCompletedInStore($surveyId, $responseId);
            $completedAt = new DateTime();
            $startTime = $_SESSION['survey_' . $surveyId]['starttime'] ?? null;
            $startedAt = $startTime ? new DateTime($startTime) : null;
            $durationSec = $startedAt ? ($completedAt->getTimestamp() - $startedAt->getTimestamp()) : null;
            $responseModel = SurveyDynamic::model($surveyId)->findByPk($responseId);
            if (!$responseModel) { $this->logEvent("[ERROR] responseModel is NULL for surveyId=$surveyId and responseId=$responseId"); return; }
            $answers = $this->extractAnswersQidAid($surveyId, $responseModel);
            $commonFields = $this->getCommonPayloadFields($surveyId, $responseId, 'survey_completed');
            $commonFields['session_end_date'] = $completedAt->format('Y-m-d H:i:s');
            $payload = array_merge($commonFields, [
                'event'           => 'survey_completed',
                'timestamp'       => $completedAt->format('Y-m-d H:i:s'),
                'survey_id'       => $surveyId,
                'response_id'     => $responseId,
                'started_at'      => $startedAt ? $startedAt->format('Y-m-d H:i:s') : null,
                'completed_at'    => $completedAt->format('Y-m-d H:i:s'),
                'duration_seconds'=> $durationSec,
                'completed'       => true,
                'answers'         => $answers
            ]);
            $this->sendChannels($payload);
            $this->logEvent("[INFO] Survey completed successfully. Duration (sec): " . ($durationSec ?? 'N/A'));
            $uuidKey = "kartaca_uuid_$surveyId";
            $customKey = "kartaca_custom_id_$surveyId";
            unset($_SESSION[$uuidKey], $_SESSION[$customKey]);
            unset($_SESSION['kartaca_uuid'], $_SESSION['kartaca_custom_id']);
            unset($_SESSION['kartaca_started_sent_' . $surveyId]);
        } catch (Throwable $e) {
            $this->logEvent("[ERROR] afterSurveyComplete Exception: " . $e->getMessage());
            $this->logEvent("[TRACE] " . $e->getTraceAsString());
        }
    }

    private function isTextType($type): bool
    {
        return in_array($type, ['S','T','U','Q',';','shortfreetext','longfreetext'], true);
    }

    private function isMatrixType($type): bool
    {
        $t = strtoupper((string)$type);
        return in_array($t, ['A','B','C','E','F','H','1'], true);
    }

    private function getQuestionType($qid): ?string
    {
        $q = Question::model()->findByPk($qid);
        if (!$q) return null;
        return $q->type ?? null;
    }

    private function getSubquestionAid(int $surveyId, int $parentQid, string $title): ?int
    {
        $title = trim($title);
        if ($title === '') return null;
        if (isset($this->subqCache[$surveyId][$parentQid][$title])) {
            return $this->subqCache[$surveyId][$parentQid][$title];
        }
        try {
            $subq = Question::model()->findByAttributes([
                'parent_qid' => $parentQid,
                'title'      => $title,
                'sid'        => $surveyId
            ]);
            if ($subq && isset($subq->qid)) {
                $this->subqCache[$surveyId][$parentQid][$title] = (int)$subq->qid;
                return (int)$subq->qid;
            }
        } catch (Throwable $e) {
            $this->logEvent("[WARN] getSubquestionAid fail pqid=$parentQid title=$title ".$e->getMessage());
        }
        return null;
    }

    private function buildAnswerIndex(int $surveyId, int $qid): void
    {
        if (isset($this->answerIndex[$surveyId][$qid])) return;
        $this->answerIndex[$surveyId][$qid] = [];
        try {
            $answers = Answer::model()->findAll('qid = :qid', [':qid' => $qid]);
            foreach ($answers as $a) {
                $code = trim((string)$a->code);
                $aid  = (int)$a->aid;
                $scale = (int)($a->scale_id ?? 0);
                if ($code !== '') {
                    if (!isset($this->answerIndex[$surveyId][$qid][$scale])) $this->answerIndex[$surveyId][$qid][$scale] = [];
                    $this->answerIndex[$surveyId][$qid][$scale][$code] = $aid;
                }
            }
            if (empty($this->answerIndex[$surveyId][$qid])) {
                $q = Question::model()->findByPk($qid);
                $t = $q ? strtoupper((string)$q->type) : '';
                if ($t !== 'M' && $t !== 'P') {
                    $subs = Question::model()->findAll('parent_qid = :pq AND sid = :sid', [':pq' => $qid, ':sid' => $surveyId]);
                    foreach ($subs as $sq) {
                        $code = trim((string)($sq->title ?? ''));
                        if ($code !== '') {
                            if (!isset($this->answerIndex[$surveyId][$qid][0])) $this->answerIndex[$surveyId][$qid][0] = [];
                            $this->answerIndex[$surveyId][$qid][0][$code] = (int)$sq->qid;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $this->logEvent('[WARN] buildAnswerIndex qid='.$qid.' '.$e->getMessage());
        }
    }

    private function resolveAidFromIndex(int $surveyId, int $qid, string $code, ?int $scaleId = null): ?int
    {
        $this->buildAnswerIndex($surveyId, $qid);
        $code = trim($code);
        $scale = ($scaleId === null) ? 0 : (int)$scaleId;
        if (isset($this->answerIndex[$surveyId][$qid][$scale][$code])) return (int)$this->answerIndex[$surveyId][$qid][$scale][$code];
        if (isset($this->answerIndex[$surveyId][$qid][0][$code])) return (int)$this->answerIndex[$surveyId][$qid][0][$code];
        foreach (($this->answerIndex[$surveyId][$qid] ?? []) as $s => $map) {
            if (isset($map[$code])) return (int)$map[$code];
        }
        return null;
    }

    private function codeToAid($qid, $code, ?int $scaleId = null)
    {
        try {
            $code = trim((string)$code);
            if ($scaleId !== null) {
                $a = Answer::model()->findByAttributes(['qid' => $qid, 'code' => $code, 'scale_id' => (int)$scaleId]);
                if ($a && isset($a->aid)) return (int)$a->aid;
            }
            $a = Answer::model()->findByAttributes(['qid' => $qid, 'code' => $code]);
            if ($a && isset($a->aid)) return (int)$a->aid;
        } catch (Throwable $e) { $this->logEvent("[ERROR] codeToAid: ".$e->getMessage()); }
        return null;
    }

    private function extractAnswersFromPostQidAid($surveyId, $step, $postData): array
    {
        $result = [];
        foreach ($postData as $key => $value) {
            if (!preg_match('/^(\d+)X(\d+)X(\d+)(SQ[0-9A-Za-z_]+)?(?:#([12]))?$/', $key, $m)) continue;
            $qid     = (int)$m[3];
            $subCode = isset($m[4]) ? (string)$m[4] : null;
            $scaleId = isset($m[5]) ? (int)$m[5] : null;
            $qtype   = $this->getQuestionType($qid);
            $values = is_array($value) ? $value : [$value];
            foreach ($values as $v) {
                $sv = trim((string)$v);
                if ($sv === '') continue;
                if ($this->isTextType($qtype)) { $this->pushAnswer($result, $qid, ['text' => $sv]); continue; }
                if (in_array(strtoupper((string)$qtype), ['M','P'], true)) {
                    $aid = $subCode ? $this->getSubquestionAid((int)$surveyId, (int)$qid, $subCode) : null;
                    $entry = ['code' => $sv];
                    if ($aid !== null) $entry['aid'] = $aid;
                    $this->pushAnswer($result, $qid, $entry);
                    continue;
                }
                if ($this->isMatrixType($qtype) && $subCode) {
                    $rowAid = $this->getSubquestionAid((int)$surveyId, (int)$qid, $subCode);
                    $entry = ['code' => $sv];
                    if ($rowAid !== null) $entry['aid'] = $rowAid;
                    $this->pushAnswer($result, $qid, $entry);
                    continue;
                }
                $aid = $this->resolveAidFromIndex((int)$surveyId, (int)$qid, $sv, $scaleId);
                if ($aid === null) $aid = $this->codeToAid($qid, $sv, $scaleId);
                if ($aid !== null) $this->pushAnswer($result, $qid, ['code' => $sv, 'aid' => (int)$aid]);
                else $this->pushAnswer($result, $qid, ['code' => $sv]);
            }
        }
        return $result;
    }

    private function extractAnswersQidAid($surveyId, $responseModel): array
    {
        $result = [];
        try {
            $attributes = $responseModel->getAttributes();
            foreach ($attributes as $fieldName => $value) {
                if (trim((string)$value) === '') continue;
                if (!preg_match('/^(\d+)X(\d+)X(\d+)(SQ[0-9A-Za-z_]+)?(?:#([12]))?$/', $fieldName, $m)) continue;
                $qid     = (int)$m[3];
                $subCode = isset($m[4]) ? (string)$m[4] : null;
                $scaleId = isset($m[5]) ? (int)$m[5] : null;
                $qtype   = $this->getQuestionType($qid);
                $values = is_array($value) ? $value : [(string)$value];
                foreach ($values as $sv) {
                    $sv = trim((string)$sv);
                    if ($sv === '') continue;
                    if ($this->isTextType($qtype)) { $this->pushAnswer($result, $qid, ['text' => $sv]); continue; }
                    if (in_array(strtoupper((string)$qtype), ['M','P'], true)) {
                        $aid = $subCode ? $this->getSubquestionAid((int)$surveyId, (int)$qid, $subCode) : null;
                        $entry = ['code' => $sv];
                        if ($aid !== null) $entry['aid'] = $aid;
                        $this->pushAnswer($result, $qid, $entry);
                        continue;
                    }
                    if ($this->isMatrixType($qtype) && $subCode) {
                        $rowAid = $this->getSubquestionAid((int)$surveyId, (int)$qid, $subCode);
                        $entry = ['code' => $sv];
                        if ($rowAid !== null) $entry['aid'] = $rowAid;
                        $this->pushAnswer($result, $qid, $entry);
                        continue;
                    }
                    $aid = $this->resolveAidFromIndex((int)$surveyId, (int)$qid, $sv, $scaleId);
                    if ($aid === null) $aid = $this->codeToAid($qid, $sv, $scaleId);
                    if ($aid !== null) $this->pushAnswer($result, $qid, ['code' => $sv, 'aid' => (int)$aid]);
                    else $this->pushAnswer($result, $qid, ['code' => $sv]);
                }
            }
        } catch (Throwable $e) {
            $this->logEvent("[ERROR] extractAnswersQidAid: " . $e->getMessage());
            $this->logEvent("[TRACE] " . $e->getTraceAsString());
        }
        return $result;
    }

    private function pushAnswer(array &$result, int $qid, array $entry): void
    {
        $idx = null;
        foreach ($result as $i => $r) { if ($r['qid'] === $qid) { $idx = $i; break; } }
        if ($idx === null) { $result[] = ['qid' => $qid, 'answers' => [$entry]]; }
        else { $result[$idx]['answers'][] = $entry; }
    }

    private function killPhpSessionById($phpSid): void
    {
        if (!$phpSid || !is_string($phpSid)) return;
        try {
            $handler = ini_get('session.save_handler');
            $savePath = rtrim((string)ini_get('session.save_path'), DIRECTORY_SEPARATOR);
            if ($handler === 'files' && $savePath) {
                $file = $savePath . DIRECTORY_SEPARATOR . 'sess_' . $phpSid;
                if (is_file($file)) { @unlink($file); $this->logEvent("[SELFSCAN] Killed PHP session file by php_sid: $file"); }
            }
        } catch (Throwable $e) {
            $this->logEvent("[SELFSCAN][WARN] killPhpSessionById: ".$e->getMessage());
        }
    }
}
