<?php

class KartacaSurveyExport extends PluginBase
{
    protected $storage = 'DbStorage';
    static protected $name = 'KartacaSurveyExport';
    static protected $description = 'Export survey QA map (question id|text + answer id|text) for all surveys with active flag';

    public function init()
    {
        date_default_timezone_set('Europe/Istanbul');
        set_error_handler(function ($errno, $errstr, $errfile, $errline) { error_log("PHP Error [$errno]: $errstr in $errfile on $errline"); });
        $this->subscribe('beforeControllerAction');
    }

    public function getPluginSettings($getValues = true)
    {
        return [
            'exportFormat' => [
                'type' => 'select',
                'label' => 'Export format',
                'options' => ['json' => 'JSON', 'csv' => 'CSV'],
                'default' => 'json',
                'current' => $this->get('exportFormat', null, null, 'json'),
            ],
            'exportFolder' => [
                'type' => 'string',
                'label' => 'Export folder (absolute path or relative to plugin)',
                'default' => 'exports',
                'current' => $this->get('exportFolder', null, null, 'exports'),
            ],
            'cronToken' => [
                'type' => 'string',
                'label' => 'Cron token (ksexport)',
                'default' => '',
                'current' => $this->get('cronToken'),
            ],
            'enableEventLogging' => [
                'type' => 'boolean',
                'label' => 'Log events',
                'default' => true,
                'current' => $this->get('enableEventLogging', null, null, true),
            ],
        ];
    }

    private function pluginDir(): string { return dirname(__FILE__); }

    private function isAbsolutePath(string $p): bool
    {
        $p = trim($p);
        if ($p === '') return false;
        if ($p[0] === '/' || $p[0] === '\\') return true;
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $p)) return true;
        if (preg_match('/^\\\\\\\\/', $p)) return true;
        return false;
    }

    private function resolveExportDir(): string
    {
        $opt = trim((string)$this->get('exportFolder', null, null, 'exports'));
        if ($opt === '') $opt = 'exports';
        $dir = $this->isAbsolutePath($opt) ? $opt : ($this->pluginDir().DIRECTORY_SEPARATOR.$opt);
        return rtrim($dir, "\\/");
    }

    private function logDir(): string { return $this->pluginDir().'/logs'; }
    private function logPath(): string { return $this->logDir().'/kartaca-export.log'; }
    private function ensureDir($path): void { if (!is_dir($path)) @mkdir($path, 0777, true); }
    private function logExport($t): void
    {
        if ($this->get('enableEventLogging', null, null, true)) {
            $this->ensureDir($this->logDir());
            @file_put_contents($this->logPath(), '['.date('Y-m-d H:i:s')."] $t\n", FILE_APPEND | LOCK_EX);
        }
    }

    private function isSurveyActive($survey): bool
    {
        try {
            $val = null;
    
            if ($survey instanceof CActiveRecord) {
                $val = $survey->getAttribute('active');
                if ($val === null) $val = $survey->getAttribute('state');
            } else {
                if (isset($survey->active))       $val = $survey->active;
                elseif (isset($survey->state))    $val = $survey->state;
            }
    
            if ($val === null) return false;
    
            $v = strtoupper(trim((string)$val));
            return ($v === 'Y' || $v === '1' || $v === 'TRUE');
        } catch (Throwable $e) {
            return false;
        }
    }

    public function beforeControllerAction()
    {
        $token = $_GET['ksexport'] ?? null;
        if (!$token) return;
        if (!hash_equals((string)$this->get('cronToken'), (string)$token)) { $this->respond(['ok' => false, 'error' => 'invalid_token']); return; }

        $formatOverride = isset($_GET['format']) ? strtolower((string)$_GET['format']) : null;
        if ($formatOverride && !in_array($formatOverride, ['json','csv'], true)) $formatOverride = null;

        $jsonStyle = isset($_GET['json_style']) ? strtolower((string)$_GET['json_style']) : null;
        if ($jsonStyle && !in_array($jsonStyle, ['pretty','compact'], true)) $jsonStyle = null;

        $debug = (isset($_GET['debug']) && $_GET['debug'] === '1');
        $force = (isset($_GET['force']) && $_GET['force'] === '1');
        $dirOverride = isset($_GET['dir']) ? trim((string)$_GET['dir']) : null;

        $sidFilter = [];
        if (!empty($_GET['sid'])) {
            foreach (explode(',', (string)$_GET['sid']) as $v) {
                $n = (int)trim($v);
                if ($n > 0) $sidFilter[] = $n;
            }
        }

        try {
            $stats = $this->runExportMap($formatOverride, $jsonStyle, $sidFilter, $dirOverride, $force, $debug);
            $this->respond(['ok' => true, 'stats' => $stats, 'debug' => $debug ? 'on' : 'off']);
        } catch (Throwable $e) {
            $this->logExport('[ERROR] export failed: '.$e->getMessage());
            $this->respond(['ok'=>false, 'error'=>'export_failed', 'message'=>$e->getMessage()]);
        }
    }

    private function respond(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        Yii::app()->end();
    }

    private function runExportMap(?string $formatOverride, ?string $jsonStyle, array $sidFilter, ?string $dirOverride, bool $force, bool $debug): array
    {
        $format = $formatOverride ?: $this->get('exportFormat', null, null, 'json');
        $jsonStyle = $jsonStyle ?: 'pretty';

        $exportDir = ($dirOverride !== null && $dirOverride !== '')
            ? ($this->isAbsolutePath($dirOverride) ? rtrim($dirOverride, "\\/") : rtrim($this->pluginDir().DIRECTORY_SEPARATOR.$dirOverride, "\\/"))
            : $this->resolveExportDir();

        $this->ensureDir($exportDir);

        $surveys = Survey::model()->findAll();
        if ($sidFilter) {
            $surveys = array_values(array_filter($surveys, function($s) use ($sidFilter) {
                return in_array((int)$s->sid, $sidFilter, true);
            }));
        }

        $out = ['total_surveys' => 0, 'per_survey' => []];

        foreach ($surveys as $s) {
            if (!$s || empty($s->sid)) continue;
            $sid = (int)$s->sid;
            if ($sid <= 0) continue;

            $active = $this->isSurveyActive($s);
            $lang = $this->getSurveyLanguage($sid);
            $title = $this->getSurveyTitle($sid, $lang);
            $questions = $this->fetchQuestions($sid);
            $answers = $this->fetchAnswersForQuestions($questions);

            $jsonPath = $exportDir.DIRECTORY_SEPARATOR."survey-{$sid}-qa-map.json";
            $qCsvPath = $exportDir.DIRECTORY_SEPARATOR."survey-{$sid}-questions.csv";
            $aCsvPath = $exportDir.DIRECTORY_SEPARATOR."survey-{$sid}-answers.csv";

            $written = [];

            if ($format === 'json') {
                if ($force || !file_exists($jsonPath)) {
                    $payload = [
                        'survey_id'    => $sid,
                        'survey_title' => $title,
                        'active'       => $active,
                        'generated_at' => date('c'),
                        'qa'           => $this->buildQA($questions, $answers),
                    ];
                    $flags = JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES;
                    if ($jsonStyle === 'pretty') $flags |= JSON_PRETTY_PRINT;
                    file_put_contents($jsonPath, json_encode($payload, $flags));
                    $this->logExport("map-json sid=$sid active=".($active?'true':'false')." -> $jsonPath");
                    $written[] = $jsonPath;
                }
            } else {
                if ($force || !file_exists($qCsvPath)) {
                    $fh = fopen($qCsvPath, 'w');
                    fputcsv($fh, ['survey_id','survey_active','qid','parent_qid','code','type','text']);
                    foreach ($questions as $q) {
                        fputcsv($fh, [
                            $sid,
                            $active ? 'true' : 'false',
                            (int)$q['qid'],
                            $q['parent_qid'] ? (int)$q['parent_qid'] : '',
                            (string)$q['code'],
                            (string)$q['type'],
                            $this->getQuestionText((int)$q['qid']),
                        ]);
                    }
                    fclose($fh);
                    $this->logExport("map-csv-questions sid=$sid active=".($active?'true':'false')." -> $qCsvPath");
                    $written[] = $qCsvPath;
                }
                if ($force || !file_exists($aCsvPath)) {
                    $fh = fopen($aCsvPath, 'w');
                    fputcsv($fh, ['survey_id','survey_active','aid','qid','code','text']);
                    foreach ($answers as $a) {
                        fputcsv($fh, [
                            $sid,
                            $active ? 'true' : 'false',
                            (int)$a['aid'],
                            (int)$a['qid'],
                            (string)$a['code'],
                            (string)$a['text']
                        ]);
                    }
                    fclose($fh);
                    $this->logExport("map-csv-answers sid=$sid active=".($active?'true':'false')." -> $aCsvPath");
                    $written[] = $aCsvPath;
                }
            }

            $out['per_survey'][$sid] = [
                'title' => $title,
                'active' => $active,
                'questions_count' => count($questions),
                'answers_count' => count($answers),
                'written' => $written,
                'skipped' => empty($written) ? 'already_exists' : '',
            ];
            $out['total_surveys']++;
        }

        return $out;
    }

    private function getSurveyLanguage($sid): string
    {
        try {
            $s = Survey::model()->findByPk($sid);
            return ($s && !empty($s->language)) ? (string)$s->language : 'en';
        } catch (Throwable $e) {
            return 'en';
        }
    }

    private function getSurveyTitle($sid, $lang='en'): string
    {
        try {
            if (class_exists('SurveyLanguageSetting')) {
                $sl = SurveyLanguageSetting::model()->findByAttributes(['surveyls_survey_id' => $sid, 'surveyls_language' => $lang]);
                if ($sl && !empty($sl->surveyls_title)) return strip_tags($sl->surveyls_title);
            }
            if (class_exists('Surveys_languagesettings')) {
                $sl = Surveys_languagesettings::model()->findByAttributes(['surveyls_survey_id' => $sid, 'surveyls_language' => $lang]);
                if ($sl && !empty($sl->surveyls_title)) return strip_tags($sl->surveyls_title);
            }
            $s = Survey::model()->findByPk($sid);
            if ($s && !empty($s->title)) return strip_tags($s->title);
        } catch (Throwable $e) {}
        return 'Survey '.$sid;
    }

    private function getQuestionText($qid): string
    {
        try {
            $q = Question::model()->findByPk($qid);
            if ($q && !empty($q->question)) return strip_tags(trim($q->question));
            if (class_exists('QuestionL10n')) {
                $l = QuestionL10n::model()->find('qid = :qid', [':qid' => $qid]);
                if ($l && !empty($l->question)) return strip_tags(trim($l->question));
            }
        } catch (Throwable $e) {}
        return 'Q'.$qid;
    }

    private function getAnswerTextByAid($aid, $fallback=null): string
    {
        try {
            if (class_exists('AnswerL10n')) {
                $al = AnswerL10n::model()->findByAttributes(['aid' => $aid]);
                if ($al && !empty($al->answer)) return strip_tags($al->answer);
            }
            $a = Answer::model()->findByPk($aid);
            if ($a && !empty($a->answer)) return strip_tags($a->answer);
        } catch (Throwable $e) {}
        return $fallback !== null ? (string)$fallback : ('A'.$aid);
    }

    private function fetchQuestions(int $sid): array
    {
        try {
            $parents = Question::model()->findAll('sid = :sid AND (parent_qid IS NULL OR parent_qid = 0)', [':sid' => $sid]);
            $out = [];
            foreach ($parents as $q) {
                $subs = Question::model()->findAll('parent_qid = :pq', [':pq' => $q->qid]);
                $subArr = [];
                foreach ($subs as $sq) {
                    $subArr[] = [
                        'qid'  => (int)$sq->qid,
                        'code' => isset($sq->title) ? (string)$sq->title : null,
                        'text' => $this->getQuestionText((int)$sq->qid),
                    ];
                }
                $out[] = [
                    'qid'          => (int)$q->qid,
                    'parent_qid'   => null,
                    'code'         => isset($q->title) ? (string)$q->title : null,
                    'type'         => isset($q->type) ? (string)$q->type : null,
                    'text'         => $this->getQuestionText((int)$q->qid),
                    'subquestions' => $subArr,
                ];
            }
            return $out;
        } catch (Throwable $e) {
            $this->logExport("[DB][ERROR] fetchQuestions sid=$sid ".$e->getMessage());
            return [];
        }
    }

    private function fetchAnswersForQuestions(array $questions): array
    {
        $out = [];
        foreach ($questions as $q) {
            $qid = (int)$q['qid'];
            $ans = [];
            try {
                $ans = Answer::model()->findAll('qid = :qid', [':qid' => $qid]);
            } catch (Throwable $e) {
                $this->logExport("[DB][WARN] fetchAnswers qid=$qid ".$e->getMessage());
            }

            $hasRealAnswers = false;
            foreach ($ans as $a) {
                $out[] = [
                    'aid'  => (int)$a->aid,
                    'qid'  => $qid,
                    'code' => (string)$a->code,
                    'text' => $this->getAnswerTextByAid((int)$a->aid, isset($a->answer) ? $a->answer : null),
                ];
                $hasRealAnswers = true;
            }

            $t = strtoupper((string)$q['type']);
            if (in_array($t, ['M','P'], true)) {
                foreach ($q['subquestions'] as $sq) {
                    $out[] = [
                        'aid'  => (int)$sq['qid'],
                        'qid'  => $qid,
                        'code' => (string)$sq['code'],
                        'text' => (string)$sq['text'],
                    ];
                }
            } elseif (!$hasRealAnswers && !empty($q['subquestions'])) {
                foreach ($q['subquestions'] as $sq) {
                    $out[] = [
                        'aid'  => (int)$sq['qid'],
                        'qid'  => $qid,
                        'code' => (string)$sq['code'],
                        'text' => (string)$sq['text'],
                    ];
                }
            }
        }
        return $out;
    }

    private function buildQA(array $questions, array $answers): array
    {
        $idx = [];
        foreach ($answers as $a) {
            $qid = (int)$a['qid'];
            if (!isset($idx[$qid])) $idx[$qid] = [];
            $idx[$qid][] = [
                'aid'  => (int)$a['aid'],
                'code' => (string)$a['code'],
                'text' => (string)$a['text'],
            ];
        }
        $out = [];
        foreach ($questions as $q) {
            $out[] = [
                'question' => [
                    'qid'  => (int)$q['qid'],
                    'code' => $q['code'],
                    'text' => $q['text'],
                ],
                'answers'  => $idx[(int)$q['qid']] ?? [],
            ];
        }
        return $out;
    }
}
