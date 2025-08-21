<?php

use Google\Cloud\PubSub\PubSubClient;

class PubSubPlugin extends PluginBase
{
    protected $storage = 'DbStorage';
    static protected $name = 'Kartaca - Pub/Sub';
    static protected $description = 'Sends LimeSurvey events to Google Cloud Pub/Sub';

    private $pubsubClient;

    public function init()
    {
        date_default_timezone_set('Europe/Istanbul');
        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            error_log("PHP Error [$errno]: $errstr in $errfile on $errline");
        });
        $this->subscribe('beforeSurveyPage');
        $this->subscribe('beforeControllerAction');
        $this->subscribe('afterSurveyComplete');
        try { $this->ensureSessionTable(); } catch (Throwable $e) { $this->logEvent('[WARN] ensureSessionTable in init: '.$e->getMessage()); }
    }

    public function getPluginSettings($getValues = true)
    {
        return [
            'gcpProjectId' => [
                'type'    => 'string',
                'label'   => 'GCP Project ID',
                'default' => '',
                'current' => $this->get('gcpProjectId'),
            ],
            'pubsubTopic' => [
                'type'    => 'string',
                'label'   => 'Pub/Sub Topic',
                'default' => 'survey-events',
                'current' => $this->get('pubsubTopic', null, null, 'survey-events'),
            ],
            'gcpCredentialsPath' => [
                'type'    => 'string',
                'label'   => 'Credentials JSON Path',
                'default' => '',
                'current' => $this->get('gcpCredentialsPath'),
            ],
            'abandonThresholdMinutes' => [
                'type'    => 'int',
                'label'   => 'Abandon threshold (minutes)',
                'help'    => 'No heartbeat/page activity within this many minutes AND not completed ⇒ send session_abandoned.',
                'default' => 3,
                'current' => (int)$this->get('abandonThresholdMinutes', null, null, 3),
            ],
            'selfScanEverySeconds' => [
                'type'    => 'int',
                'label'   => 'Self-scan interval (seconds)',
                'help'    => 'Abandonment scan is performed at most this frequently per incoming request.',
                'default' => 60,
                'current' => (int)$this->get('selfScanEverySeconds', null, null, 60),
            ],
            'enableEventLogging' => [
                'type'    => 'boolean',
                'label'   => 'Event Logging Active',
                'default' => true,
                'current' => $this->get('enableEventLogging', null, null, true),
            ],
            'enableJsonLogging' => [
                'type'    => 'boolean',
                'label'   => 'JSON Logging Active',
                'default' => true,
                'current' => $this->get('enableJsonLogging', null, null, true),
            ],
        ];
    }

    private function isPgsql(): bool
    {
        try { return Yii::app()->db->driverName === 'pgsql'; } catch (Throwable $e) { return false; }
    }

    private function getLogDir(): string
    {
        return dirname(__FILE__) . '/logs';
    }
    private function getLogPath(): string
    {
        return $this->getLogDir() . '/kartaca-event.log';
    }
    private function getJsonLogPath(): string
    {
        return $this->getLogDir() . '/kartaca-data.ndjson';
    }
    private function ensureLogDirectory(string $filePath): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    }
    private function logEvent(string $text): void
    {
        if ($this->get('enableEventLogging', null, null, true)) {
            $this->ensureLogDirectory($this->getLogPath());
            $ts = date('Y-m-d H:i:s');
            @file_put_contents($this->getLogPath(), "[$ts] $text\n", FILE_APPEND | LOCK_EX);
        }
    }
    private function logJson(array $data): void
    {
        if ($this->get('enableJsonLogging', null, null, true)) {
            $this->ensureLogDirectory($this->getJsonLogPath());
            @file_put_contents($this->getJsonLogPath(), json_encode($data, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    private function getPubSubClient(): ?PubSubClient
    {
        if ($this->pubsubClient) return $this->pubsubClient;

        $projectId = trim((string)$this->get('gcpProjectId', null, null, ''));
        if (!$projectId) { $this->logEvent('[PUBSUB] Missing gcpProjectId'); return null; }

        $opts = ['projectId' => $projectId];
        $credPath = trim((string)$this->get('gcpCredentialsPath', null, null, ''));
        if ($credPath) $opts['keyFilePath'] = $credPath;

        try { $this->pubsubClient = new PubSubClient($opts); }
        catch (Throwable $e) { $this->logEvent('[PUBSUB][ERROR] '.$e->getMessage()); return null; }

        return $this->pubsubClient;
    }

    private function buildAttributesFromPayload(array $p): array
    {
        $a = [];
        if (isset($p['event']))          $a['event']       = (string)$p['event'];
        if (isset($p['survey_id']))      $a['survey_id']   = (string)$p['survey_id'];
        if (isset($p['response_id']))    $a['response_id'] = (string)$p['response_id'];
        if (isset($p['step']))           $a['step']        = (string)$p['step'];
        if (isset($p['step_submitted'])) $a['step']        = (string)$p['step_submitted'];
        if (isset($p['uuid']))           $a['uuid']        = (string)$p['uuid'];
        if (isset($p['custom_id']))      $a['custom_id']   = (string)$p['custom_id'];
        $a['source'] = 'PubSubPlugin';
        $a['schema'] = 'v1';
        return $a;
    }

    private function sendPubSub(array $payload): void
    {
        $client = $this->getPubSubClient();
        if (!$client) { $this->logEvent('[PUBSUB] Client not ready'); return; }

        $topicName = trim((string)$this->get('pubsubTopic', null, null, 'survey-events'));
        try {
            $topic = $client->topic($topicName);
            $topic->publish([
                'data'       => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'attributes' => $this->buildAttributesFromPayload($payload),
            ]);
            $this->logEvent('PubSub SUCCESS: '.($payload['event'] ?? 'unknown'));
        } catch (Throwable $e) {
            $this->logEvent('[PUBSUB][ERROR] '.($payload['event'] ?? 'unknown').' '.$e->getMessage());
        }
    }

    private function ensureSessionTable(): void
    {
        $db = Yii::app()->db;
        $table = $db->schema->getTable('kartaca_webhook_sessions', true);
        if ($table) {
            try {
                Yii::app()->db->createCommand("ALTER TABLE public.kartaca_webhook_sessions ADD COLUMN IF NOT EXISTS php_sid VARCHAR(191) NULL;")->execute();
            } catch (Throwable $e) { $this->logEvent('[WARN] add php_sid: '.$e->getMessage()); }
            return;
        }
        $sql = "CREATE TABLE IF NOT EXISTS public.kartaca_webhook_sessions (
            id SERIAL PRIMARY KEY,
            session_id VARCHAR(191) NOT NULL,
            survey_id INTEGER NOT NULL,
            response_id INTEGER NULL,
            step INTEGER NULL,
            started_at TIMESTAMP NULL,
            last_seen TIMESTAMP NOT NULL,
            completed BOOLEAN NOT NULL DEFAULT FALSE,
            abandoned_sent BOOLEAN NOT NULL DEFAULT FALSE,
            abandoned_sent_at TIMESTAMP NULL,
            uuid VARCHAR(64) NULL,
            custom_id VARCHAR(128) NULL,
            php_sid VARCHAR(191) NULL,
            CONSTRAINT uniq_session UNIQUE (session_id)
        );";
        $db->createCommand($sql)->execute();
    }
    private function computeSessionId($surveyId, $responseId): string
    {
        return 'survey_' . $surveyId . '_' . session_id();
    }

    private function getResponseIdFromSession($surveyId)
    {
        return $_SESSION['survey_'.$surveyId]['srid']
            ?? $_SESSION['survey_'.$surveyId]['id']
            ?? null;
    }

    private function getAnswerTextByCode($surveyId, $qid, $code)
    {
        try {
            $answer = Answer::model()->findByAttributes(['qid' => $qid, 'code' => $code]);
            if ($answer && isset($answer->aid)) {
                $aid = $answer->aid;
                $answerL10n = AnswerL10n::model()->findByAttributes(['aid' => $aid]);
                if ($answerL10n && !empty($answerL10n->answer)) {
                    return strip_tags($answerL10n->answer);
                }
            }
            if ($code === 'Y') return '1';
            if ($code === 'N') return '0';
        } catch (Throwable $e) {
            $this->logEvent("[ERROR] getAnswerTextByCode Exception: " . $e->getMessage());
        }
        return $code;
    }

    private function resolveSubquestionText($subq, $fallback)
    {
        if ($subq && isset($subq->question)) return strip_tags($subq->question);
        if ($subq && isset($subq->qid)) {
            $subL10n = QuestionL10n::model()->findByAttributes(['qid' => $subq->qid]);
            return ($subL10n && $subL10n->question) ? strip_tags($subL10n->question) : $fallback;
        }
        return $fallback;
    }

    private function getQuestionText($qid)
    {
        $questionModel = Question::model()->findByPk($qid);
        if ($questionModel && !empty($questionModel->question)) {
            return strip_tags(trim($questionModel->question));
        }
        $l10nModel = QuestionL10n::model()->find('qid = :qid', [':qid' => $qid]);
        if ($l10nModel && !empty($l10nModel->question)) {
            return strip_tags(trim($l10nModel->question));
        }
        $this->logEvent("[WARN] getQuestionText: Question text not found: QID=$qid");
        return '[Question not found]';
    }

    private function upsertActiveSession(int $surveyId, $responseId, $step = null): void
    {
        $this->ensureSessionTable();
        $db = Yii::app()->db;
        $phpSid = session_id();
        $newSid = $this->computeSessionId($surveyId, $responseId);
        $now = date('Y-m-d H:i:s');
        $uuid = $_SESSION['kartaca_uuid'] ?? null;
        $custom = $_SESSION['kartaca_custom_id'] ?? null;

        $existingAbandoned = $db->createCommand("SELECT abandoned_sent FROM kartaca_webhook_sessions WHERE session_id=:sid")
            ->bindParam(':sid', $newSid)->queryRow();
        if ($existingAbandoned && !empty($existingAbandoned['abandoned_sent'])) {
            $this->logEvent("[INFO] Heartbeat ignored for abandoned session: $newSid");
            return;
        }

        $row = $db->createCommand("SELECT id, step, php_sid FROM kartaca_webhook_sessions WHERE session_id=:sid")
            ->bindParam(':sid', $newSid)->queryRow();

        if (!$row && $responseId) {
            $oldSid = 'survey_'.$surveyId.'_'.$phpSid;
            if ($oldSid !== $newSid) {
                $oldRow = $db->createCommand("SELECT id, step, abandoned_sent, php_sid FROM kartaca_webhook_sessions WHERE session_id=:sid")
                    ->bindParam(':sid', $oldSid)->queryRow();
                if ($oldRow && empty($oldRow['abandoned_sent'])) {
                    $newStep = $step !== null
                        ? (is_numeric($oldRow['step']) ? max((int)$oldRow['step'], (int)$step) : (int)$step)
                        : $oldRow['step'];
                    $db->createCommand("
                        UPDATE kartaca_webhook_sessions
                        SET session_id=:newsid, response_id=:rid, step=:stp, last_seen=:ls, uuid=:uuid, custom_id=:cid,
                            php_sid = COALESCE(php_sid, :phpsid)
                        WHERE id=:id
                    ")->bindValues([
                        ':newsid'=>$newSid, ':rid'=>$responseId, ':stp'=>$newStep, ':ls'=>$now,
                        ':uuid'=>$uuid, ':cid'=>$custom, ':phpsid'=>$phpSid, ':id'=>$oldRow['id']
                    ])->execute();
                    return;
                }
            }
        }

        if ($row) {
            if ($step !== null) {
                $newStep = is_numeric($row['step']) ? max((int)$row['step'], (int)$step) : (int)$step;
                $db->createCommand("
                    UPDATE kartaca_webhook_sessions
                    SET last_seen=:ls, step=:stp, response_id=:rid, uuid=:uuid, custom_id=:cid,
                        php_sid = COALESCE(php_sid, :phpsid)
                    WHERE id=:id
                ")->bindValues([':ls'=>$now, ':stp'=>$newStep, ':rid'=>$responseId, ':uuid'=>$uuid, ':cid'=>$custom, ':phpsid'=>$phpSid, ':id'=>$row['id']])->execute();
            } else {
                $db->createCommand("
                    UPDATE kartaca_webhook_sessions
                    SET last_seen=:ls, response_id=:rid, uuid=:uuid, custom_id=:cid,
                        php_sid = COALESCE(php_sid, :phpsid)
                    WHERE id=:id
                ")->bindValues([':ls'=>$now, ':rid'=>$responseId, ':uuid'=>$uuid, ':cid'=>$custom, ':phpsid'=>$phpSid, ':id'=>$row['id']])->execute();
            }
        } else {
            $db->createCommand("
                INSERT INTO kartaca_webhook_sessions
                (session_id, survey_id, response_id, step, started_at, last_seen, uuid, custom_id, php_sid)
                VALUES (:sid, :sur, :rid, :stp, :sta, :ls, :uuid, :cid, :phpsid)
            ")->bindValues([
                ':sid'=>$newSid, ':sur'=>$surveyId, ':rid'=>$responseId,
                ':stp'=>$step, ':sta'=>$now, ':ls'=>$now, ':uuid'=>$uuid, ':cid'=>$custom, ':phpsid'=>$phpSid
            ])->execute();
        }

        try {
            $db->createCommand("
                DELETE FROM kartaca_webhook_sessions
                WHERE session_id <> :sid
                  AND survey_id = :sur
                  AND COALESCE(php_sid, '') = :phpsid
                  AND completed = FALSE
                  AND abandoned_sent = FALSE
            ")->bindValues([':sid'=>$newSid, ':sur'=>$surveyId, ':phpsid'=>$phpSid])->execute();
        } catch (Throwable $e) {
            $this->logEvent('[WARN] cleanup duplicates: '.$e->getMessage());
        }
    }

    private function markCompletedInStore(int $surveyId, $responseId): void
    {
        $this->ensureSessionTable();
        $db   = Yii::app()->db;
        $sid  = $this->computeSessionId($surveyId, $responseId);
        $now  = date('Y-m-d H:i:s');

        $db->createCommand("
            UPDATE kartaca_webhook_sessions
            SET completed = TRUE, last_seen = :ls
            WHERE session_id = :sid
        ")->bindValues([':ls'=>$now, ':sid'=>$sid])->execute();
    }

    private function runCronCheck(): array
    {
        $this->ensureSessionTable();
        $db = Yii::app()->db;
        $mins = max(1, (int)$this->get('abandonThresholdMinutes', null, null, 3));
        $cutoff = date('Y-m-d H:i:s', time() - $mins * 60);

        if ($this->isPgsql()) {
            $rows = $db->createCommand("
                SELECT *
                FROM (
                    SELECT
                        s.*,
                        ROW_NUMBER() OVER (
                            PARTITION BY s.survey_id, COALESCE(s.php_sid, s.session_id)
                            ORDER BY (s.response_id IS NOT NULL) DESC,
                                     COALESCE(s.step,0) DESC,
                                     s.last_seen DESC,
                                     s.id DESC
                        ) AS rn
                    FROM kartaca_webhook_sessions s
                    WHERE s.completed = FALSE
                      AND s.abandoned_sent = FALSE
                      AND s.last_seen < :cutoff
                ) x
                WHERE x.rn = 1
            ")->bindParam(':cutoff', $cutoff)->queryAll();
        } else {
            $rows = $db->createCommand("
                SELECT s.*
                FROM kartaca_webhook_sessions s
                WHERE s.completed=FALSE
                  AND s.abandoned_sent=FALSE
                  AND s.last_seen < :cutoff
                  AND COALESCE(s.step,0) = COALESCE((
                      SELECT MAX(COALESCE(s2.step,0))
                      FROM kartaca_webhook_sessions s2
                      WHERE s2.survey_id = s.survey_id
                        AND COALESCE(s2.php_sid, s2.session_id) = COALESCE(s.php_sid, s.session_id)
                        AND s2.completed=FALSE
                        AND s2.abandoned_sent=FALSE
                        AND s2.last_seen < :cutoff
                  ), COALESCE(s.step,0))
            ")->bindParam(':cutoff', $cutoff)->queryAll();
        }

        $sent = 0;
        foreach ($rows as $r) {
            $affected = $db->createCommand("
                UPDATE kartaca_webhook_sessions
                SET abandoned_sent = TRUE, abandoned_sent_at = :ts
                WHERE id = :id AND abandoned_sent = FALSE
            ")->bindValues([':ts' => date('Y-m-d H:i:s'), ':id' => $r['id']])->execute();

            if ($affected !== 1) { continue; }

            $db->createCommand("
            UPDATE kartaca_webhook_sessions
            SET abandoned_sent = TRUE, abandoned_sent_at = :ts
            WHERE survey_id = :sur
              AND id <> :id
              AND abandoned_sent = FALSE
              AND (
                    ( :phpsid IS NOT NULL AND php_sid = :phpsid )
                 OR ( :rid IS NOT NULL AND response_id = :rid )
                 OR ( session_id = :sidExact )
              )
            ")->bindValues([
                ':ts'      => date('Y-m-d H:i:s'),
                ':sur'     => (int)$r['survey_id'],
                ':id'      => $r['id'],
                ':phpsid'  => $r['php_sid'] ?? null,
                ':rid'     => $r['response_id'] ?? null,
                ':sidExact'=> $r['session_id'],
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

            $this->sendPubSub($payload);
            $this->logJson($payload);

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
        $fields = [
            'session_id' => $this->computeSessionId($surveyId, $responseId),
            'event_time' => time(),
        ];
        if (isset($_SESSION['survey_' . $surveyId]['starttime'])) {
            $fields['session_start_date'] = $_SESSION['survey_' . $surveyId]['starttime'];
        }
        if ($event === 'survey_completed') {
            $fields['session_end_date'] = date('Y-m-d H:i:s');
        }
        if (isset($_SESSION['kartaca_uuid']))      { $fields['uuid']      = $_SESSION['kartaca_uuid']; }
        if (isset($_SESSION['kartaca_custom_id'])) { $fields['custom_id'] = $_SESSION['kartaca_custom_id']; }
        return $fields;
    }

    private function enforceAbandonedGuard(int $surveyId): void
    {
        try {
            $db = Yii::app()->db;
            $phpSid = session_id();
            $row = $db->createCommand("
                SELECT abandoned_sent FROM kartaca_webhook_sessions
                WHERE php_sid = :phpsid AND survey_id = :sid
                ORDER BY id DESC LIMIT 1
            ")->bindValues([':phpsid'=>$phpSid, ':sid'=>$surveyId])->queryRow();

            if ($row && !empty($row['abandoned_sent'])) {
                @session_regenerate_id(true);
                unset($_SESSION['survey_' . $surveyId]);
                unset($_SESSION['ps_started_sent_' . $surveyId]);
                $this->logEvent("[GUARD] Abandoned session blocked via php_sid for survey_$surveyId");
            }
        } catch (Throwable $e) {
            $this->logEvent("[GUARD][ERROR] ".$e->getMessage());
        }
    }

    public function beforeSurveyPage()
    {
        $this->maybeRunAbandonScan();

        $event    = $this->getEvent();
        $surveyId = (int)$event->get('surveyId');

        $this->enforceAbandonedGuard($surveyId);

        foreach (['uuid', 'custom_id'] as $key) {
            if (isset($_GET[$key])) {
                $_SESSION["kartaca_$key"] = $_GET[$key];
            }
        }

        $responseId = $this->getResponseIdFromSession($surveyId);
        $rawStep    = $_SESSION['survey_' . $surveyId]['step'] ?? null;
        $stepInt    = is_numeric($rawStep) ? (int)$rawStep : null;

        if (empty($_SESSION['survey_' . $surveyId]['starttime'])) {
            $_SESSION['survey_' . $surveyId]['starttime'] = date('Y-m-d H:i:s');
            unset($_SESSION['ps_started_sent_' . $surveyId]);
            $this->logEvent("Starttime set manually for survey_$surveyId at " . $_SESSION['survey_' . $surveyId]['starttime']);
        }

        $startedFlagKey   = 'ps_started_sent_' . $surveyId;
        $shouldStartEvent = empty($_SESSION[$startedFlagKey]) && ($stepInt === null || $stepInt <= 1);

        if ($shouldStartEvent) {
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
            $this->sendPubSub($payload);
            $this->logJson($payload);
            $this->logEvent("Survey started (" . ($stepInt !== null ? "step=$stepInt" : "welcome") . ")");
            $_SESSION[$startedFlagKey] = 1;
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
            $answers = $this->extractAnswersFromPost($surveyId, $previousStep, $_POST);

            if (!empty($answers)) {
                $submitPayload = array_merge(
                    $this->getCommonPayloadFields($surveyId, $responseId, 'page_submit'),
                    [
                        'event'          => 'page_submit',
                        'timestamp'      => date('Y-m-d H:i:s'),
                        'survey_id'      => (int)$surveyId,
                        'response_id'    => $responseId,
                        'step_submitted' => $previousStep,
                        'answers'        => $answers,
                    ]
                );
                $this->sendPubSub($submitPayload);
                $this->logJson($submitPayload);
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
            $durationSeconds = ($startedAt) ? $completedAt->getTimestamp() - $startedAt->getTimestamp() : null;

            $responseModel = SurveyDynamic::model($surveyId)->findByPk($responseId);
            if (!$responseModel) {
                $this->logEvent("[ERROR] responseModel is NULL for surveyId=$surveyId and responseId=$responseId");
                return;
            }

            $answers = $this->extractAnswers($surveyId, $responseModel);
            $commonFields = $this->getCommonPayloadFields($surveyId, $responseId, 'survey_completed');
            $commonFields['session_end_date'] = $completedAt->format('Y-m-d H:i:s');

            $payload = array_merge($commonFields, [
                'event'            => 'survey_completed',
                'timestamp'        => $completedAt->format('Y-m-d H:i:s'),
                'survey_id'        => $surveyId,
                'response_id'      => $responseId,
                'started_at'       => $startedAt ? $startedAt->format('Y-m-d H:i:s') : null,
                'completed_at'     => $completedAt->format('Y-m-d H:i:s'),
                'duration_seconds' => $durationSeconds,
                'completed'        => true,
                'answers'          => $answers
            ]);

            $this->sendPubSub($payload);
            $this->logJson($payload);
            $this->logEvent("[INFO] Survey completed successfully. Duration (sec): " . ($durationSeconds ?? 'N/A'));
            unset($_SESSION['ps_started_sent_' . $surveyId]);
        } catch (Throwable $e) {
            $this->logEvent("[ERROR] afterSurveyComplete Exception: " . $e->getMessage());
            $this->logEvent("[TRACE] " . $e->getTraceAsString());
        }
    }

    private function extractAnswersFromPost($surveyId, $step, $postData): array
    {
        $answers = [];
        foreach ($postData as $key => $value) {
            if (!preg_match('/^(\d+)X(\d+)X(\d+)(SQ\d+)?$/', $key, $m)) continue;

            $qid     = (int)$m[3];
            $subCode = $m[4] ?? null;

            $question = Question::model()->findByPk($qid);
            if (!$question) continue;

            $questionText = $this->getQuestionText($qid);
            $subText = null;

            if ($subCode) {
                $subq = Question::model()->findByAttributes([
                    'parent_qid' => $qid,
                    'title'      => $subCode,
                    'sid'        => $surveyId,
                ]);
                $subText = $this->resolveSubquestionText($subq, $subCode);
            }

            $displayValue = $this->getAnswerTextByCode($surveyId, $qid, $value);
            $entry = $subText ? ['subquestion' => $subText, 'value' => $displayValue] : ['value' => $displayValue];

            $index = array_search($questionText, array_column($answers, 'question'));
            if ($index !== false) {
                $answers[$index]['answers'][] = $entry;
            } else {
                $answers[] = ['question' => $questionText, 'answers' => [$entry]];
            }
        }
        return $answers;
    }

    private function extractAnswers($surveyId, $responseModel): array
    {
        $answers = [];
        try {
            $attributes = $responseModel->getAttributes();
            foreach ($attributes as $fieldName => $value) {
                if (trim((string)$value) === '') continue;
                if (!preg_match('/^(\d+)X(\d+)X(\d+)(SQ\d+)?$/', $fieldName, $m)) continue;

                $qid     = (int)$m[3];
                $subCode = $m[4] ?? null;
                $questionText = $this->getQuestionText($qid);
                $subText = null;

                if ($subCode && strtoupper($value) === 'Y') {
                    $subq = Question::model()->findByAttributes([
                        'parent_qid' => $qid,
                        'title'      => $subCode,
                        'sid'        => $surveyId,
                    ]);
                    if ($subq && isset($subq->question)) {
                        $displayValue = strip_tags($subq->question);
                    } elseif ($subq && isset($subq->qid)) {
                        $subL10n = QuestionL10n::model()->findByAttributes(['qid' => $subq->qid]);
                        $displayValue = ($subL10n && $subL10n->question) ? strip_tags($subL10n->question) : $subCode;
                    } else {
                        $displayValue = $subCode;
                        $this->logEvent("[WARN] Subquestion text not found for QID=$qid, SubCode=$subCode");
                    }
                    $entry = ['value' => $displayValue];
                    $index = array_search($questionText, array_column($answers, 'question'));
                    if ($index !== false) $answers[$index]['answers'][] = $entry;
                    else $answers[] = ['question' => $questionText, 'answers' => [$entry]];
                    continue;
                }

                if ($subCode) {
                    $subq = Question::model()->findByAttributes([
                        'parent_qid' => $qid,
                        'title'      => $subCode,
                        'sid'        => $surveyId,
                    ]);
                    if ($subq && isset($subq->question)) {
                        $subText = strip_tags($subq->question);
                    } elseif ($subq && isset($subq->qid)) {
                        $subL10n = QuestionL10n::model()->findByAttributes(['qid' => $subq->qid]);
                        $subText = ($subL10n && $subL10n->question) ? strip_tags($subL10n->question) : $subCode;
                    } else {
                        $subText = $subCode;
                        $this->logEvent("[WARN] Subquestion text not found for QID=$qid, SubCode=$subCode");
                    }
                }

                $displayValue = $this->getAnswerTextByCode($surveyId, $qid, $value);
                $entry = $subText ? ['subquestion' => $subText, 'value' => $displayValue] : ['value' => $displayValue];

                $index = array_search($questionText, array_column($answers, 'question'));
                if ($index !== false) $answers[$index]['answers'][] = $entry;
                else $answers[] = ['question' => $questionText, 'answers' => [$entry]];
            }
        } catch (Throwable $e) {
            $this->logEvent("[ERROR] extractAnswers Exception: " . $e->getMessage());
            $this->logEvent("[TRACE] " . $e->getTraceAsString());
        }
        return $answers;
    }

    private function killAbandonedSessionSafe(string $storedSessionId): void
    {
        $parts = explode('_', $storedSessionId, 3);
        if (count($parts) !== 3) return;
        $suffix = $parts[2];
        if (ctype_digit($suffix)) return;
        try {
            $handler = ini_get('session.save_handler');
            $savePath = rtrim((string)ini_get('session.save_path'), DIRECTORY_SEPARATOR);
            if ($handler === 'files' && $savePath) {
                $file = $savePath . DIRECTORY_SEPARATOR . 'sess_' . $suffix;
                if (is_file($file)) { @unlink($file); $this->logEvent("[SELFSCAN] Killed PHP session file: $file"); }
            }
        } catch (Throwable $e) {
            $this->logEvent("[SELFSCAN][WARN] killAbandonedSessionSafe: ".$e->getMessage());
        }
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
