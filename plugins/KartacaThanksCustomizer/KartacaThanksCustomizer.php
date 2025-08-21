<?php

class KartacaThanksCustomizer extends PluginBase
{
    protected $storage = 'DbStorage';
    static protected $name = 'KartacaThanksCustomizer';
    static protected $description = 'Customize completion title and image';

    public function init()
    {
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');
        $this->subscribe('afterSurveyComplete');
        $this->subscribe('beforeTwigRenderTemplate');
    }

    private function guessSid()
    {
        try {
            $req = Yii::app()->request;
            foreach (['sid','surveyid','surveyId','iSurveyID'] as $k) {
                $v = (int)$req->getParam($k, 0);
                if ($v > 0) return $v;
            }
        } catch (\Throwable $e) {}
        foreach (($_SESSION ?? []) as $k => $v) {
            if (strpos($k, 'survey_') === 0) {
                $parts = explode('_', $k);
                if (isset($parts[1]) && ctype_digit($parts[1])) return (int)$parts[1];
            }
        }
        return 0;
    }

    private function isFilled($v) { return is_string($v) && trim($v) !== ''; }
    private function defaultTitle() { return 'Thank you!'; }
    private function defaultImg()   { return '/survenic/themes/survey/kartaca_survey/files/survenic-thanks.png'; }

    private function resolveTitleOrNull($sid)
    {
        $survey = $sid ? $this->get('thanksTitle', 'Survey', $sid, null) : null;
        if ($this->isFilled($survey)) return $survey;
        $global = $this->get('globalThanksTitle', null, null, null);
        if ($this->isFilled($global)) return $global;
        return null;
    }

    private function resolveImg($sid)
    {
        $global = $this->get('globalThanksImageUrl', null, null, $this->defaultImg());
        $survey = $sid ? $this->get('thanksImageUrl', 'Survey', $sid, null) : null;
        return $this->isFilled($survey) ? $survey : $global;
    }

    public function getPluginSettings($getValues = true)
    {
        return [
            'globalThanksTitle' => [
                'type'    => 'string',
                'label'   => 'Global title',
                'help'    => 'Blank = default title',
                'default' => '',
                'current' => $this->get('globalThanksTitle', null, null, ''),
            ],
            'globalThanksImageUrl' => [
                'type'    => 'string',
                'label'   => 'Global image URL',
                'help'    => 'Blank = default image',
                'default' => $this->defaultImg(),
                'current' => $this->get('globalThanksImageUrl', null, null, $this->defaultImg()),
            ],
        ];
    }

    public function beforeSurveySettings()
    {
        try {
            $event = $this->getEvent();
            $sid = (int)$event->get('survey');
            $event->set("surveysettings.{$this->id}", [
                'label'    => 'Kartaca Thanks Customizer',
                'settings' => [
                    'thanksTitle' => [
                        'type'    => 'string',
                        'label'   => 'Survey title (empty = global; both empty = theme default)',
                        'current' => $this->get('thanksTitle', 'Survey', $sid, null),
                    ],
                    'thanksImageUrl' => [
                        'type'    => 'string',
                        'label'   => 'Survey image URL (empty = global)',
                        'current' => $this->get('thanksImageUrl', 'Survey', $sid, null),
                    ],
                ],
            ]);
        } catch (\Throwable $e) {}
    }

    public function newSurveySettings()
    {
        try {
            $event = $this->getEvent();
            $sid = (int)$event->get('survey');
            $settings = $event->get('settings');
            if (is_array($settings)) {
                foreach ($settings as $name => $value) {
                    if (in_array($name, ['thanksTitle','thanksImageUrl'], true)) {
                        $this->set($name, $value, 'Survey', $sid);
                    }
                }
            }
        } catch (\Throwable $e) {}
    }

    public function afterSurveyComplete()
    {
        try {
            $e = $this->getEvent();
            $sid = (int)$e->get('surveyId');
            if (!$sid) { $sid = $this->guessSid(); }

            $title = $this->resolveTitleOrNull($sid);
            $img   = $this->resolveImg($sid);

            $titleOverride = ($title !== null);
            $jsonTitle = $titleOverride ? json_encode($title, JSON_UNESCAPED_UNICODE) : null;
            $jsonImg   = json_encode($img, JSON_UNESCAPED_SLASHES);

            $js = "<script>(function(){try{var root=document.querySelector('.completion-card');if(!root)return;";
            if ($titleOverride) {
                $js .= "var h1=root.querySelector('.completion-title h1');if(h1)h1.textContent={$jsonTitle};";
            }
            $js .= "var im=root.querySelector('.completion-image img');if(im&&{$jsonImg})im.setAttribute('src',{$jsonImg}.replace(/^\"+|\"+\$/g,''));";
            if ($titleOverride) {
                $js .= "if(im)im.setAttribute('alt',{$jsonTitle}.replace(/^\"+|\"+\$/g,''));";
            }
            $js .= "}catch(e){}})();</script>";

            $content = $e->getContent($this);
            if (method_exists($content, 'addContent')) {
                $content->addContent($js);
            } else {
                $e->append('sTwigBlocks', $js);
            }
        } catch (\Throwable $ex) {}
    }

    public function beforeTwigRenderTemplate()
    {
        try {
            $e = $this->getEvent();
            $tpl = $e->get('sTemplate');
            if (!is_string($tpl) || stripos($tpl, 'submit') === false) { return; }

            $sid = $this->guessSid();
            $title = $this->resolveTitleOrNull($sid);
            $img   = $this->resolveImg($sid);

            $titleOverride = ($title !== null);
            $jsonTitle = $titleOverride ? json_encode($title, JSON_UNESCAPED_UNICODE) : null;
            $jsonImg   = json_encode($img, JSON_UNESCAPED_SLASHES);

            $js = "<script>(function(){try{var root=document.querySelector('.completion-card');if(!root)return;";
            if ($titleOverride) {
                $js .= "var h1=root.querySelector('.completion-title h1');if(h1)h1.textContent={$jsonTitle};";
            }
            $js .= "var im=root.querySelector('.completion-image img');if(im&&{$jsonImg})im.setAttribute('src',{$jsonImg}.replace(/^\"+|\"+\$/g,''));";
            if ($titleOverride) {
                $js .= "if(im)im.setAttribute('alt',{$jsonTitle}.replace(/^\"+|\"+\$/g,''));";
            }
            $js .= "}catch(e){}})();</script>";

            $e->append('sTwigBlocks', $js);
        } catch (\Throwable $ex) {}
    }
}
