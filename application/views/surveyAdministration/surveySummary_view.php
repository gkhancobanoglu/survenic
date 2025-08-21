<?php

/**
 * Survey default view
 *
 * @var SurveyAdministrationController $this
 * @var Survey $oSurvey
 */

if (!isset($iSurveyID)) {
    $iSurveyID = $oSurvey->sid;
}

// DO NOT REMOVE - This is for automated testing
echo viewHelper::getViewTestTag('surveySummary');

$surveyid = $oSurvey->sid;
$templateModel = Template::model()->findByPk($oSurvey->oOptions->template);

$surveylocale = Permission::model()->hasSurveyPermission($iSurveyID, 'surveylocale', 'read');
$surveysettings = Permission::model()->hasSurveyPermission($iSurveyID, 'surveysettings', 'read');
$respstatsread = Permission::model()->hasSurveyPermission($iSurveyID, 'responses', 'read')
    || Permission::model()->hasSurveyPermission($iSurveyID, 'statistics', 'read')
    || Permission::model()->hasSurveyPermission($iSurveyID, 'responses', 'export');

?>
<div class="ls-card-grid">

    <?php
    if (isset($surveyActivationFeedback)) {
        $this->renderPartial('/surveyAdministration/surveyActivation/_feedbackOpenAccess', ['surveyId' => $iSurveyID]);
    }

    $possiblePanelFolder = realpath(Yii::app()->getConfig('rootdir') . '/application/views/admin/survey/subview/surveydashboard/');
    $possiblePanels = scandir($possiblePanelFolder);

    
    $excludedForLimitedRoles = [
        '005-hintsandwarnings.twig',
        '006dbanalytics.twig'
    ];

    $currentUser = Yii::app()->user;
    $isAdmin = $currentUser->getName() === 'admin'; 

    echo '<div class="row survey-summary mt-4">';
    $count = 0;

    foreach ($possiblePanels as $panel) {
        if (!preg_match('/^.*\.twig$/', (string)$panel)) {
            continue;
        }

        if (!$isAdmin && in_array($panel, $excludedForLimitedRoles)) {
            continue;
        }

        
        if ($count % 2 === 0 && $count !== 0) {
            echo '</div><div class="row survey-summary mt-4">';
        }

        echo '<div class="col-12 col-xl-6 mb-4">';
        $surveyTextContent = $oSurvey->currentLanguageSettings->attributes;
        echo App()->twigRenderer->renderViewFromFile(
            '/application/views/admin/survey/subview/surveydashboard/' . $panel,
            get_defined_vars(),
            true
        );
        echo '</div>';

        $count++;
    }

    echo '</div>'; 
    ?>
</div>
