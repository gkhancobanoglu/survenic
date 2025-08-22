<?php

/**
 * The welcome page is the home page
 * TODO : make a recursive function, taking any number of box in the database, calculating how much rows are needed.
 */

/**
 * @var $belowLogoHtml String
 * @var $this AdminController
 * @var $oldDashboard bool
 **/

// DO NOT REMOVE This is for automated testing to validate we see that page
echo viewHelper::getViewTestTag('index');
?>

<?php
// Boxes are defined by user. We still want the default boxes to be translated.
gT('Create survey');
gT('Create a new survey');
gT('List surveys');
gT('List available surveys');
gT('Global settings');
gT('Edit global settings');
gT('ComfortUpdate');
gT('Stay safe and up to date');
gT('Label sets');
gT('Edit label sets');
gT('Themes');
?>

<!-- Welcome view -->
<div class="welcome">

    <!-- Logo & Presentation -->
    <?php if ($bShowLogo && $oldDashboard) : ?>
        <div class="jumbotron" id="welcome-jumbotron">
            <img alt="logo" src="<?php echo LOGO_URL; ?>" id="lime-logo" class="profile-img-card img-fluid" />
            <p class="d-xs-none"><?php echo PRESENTATION; ?></p>
        </div>
    <?php endif; ?>

    <!-- Extra banner after logo-->
    <?= $belowLogoHtml ?>

    <!-- Message when first start -->
    <?php if ($countSurveyList == 0  && Permission::model()->hasGlobalPermission('surveys', 'create')) : ?>
        <script type="text/javascript">
            window.onload = function() {
                var welcomeModal = new bootstrap.Modal(document.getElementById('welcomeModal'));
                welcomeModal.show()
            };
        </script>

        <div class="modal fade" id="welcomeModal" aria-labelledby="welcome-modal-title">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="welcome-modal-title">
                            <?php echo sprintf(gT("Welcome to %s!"), 'Survenic'); ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" aria-hidden="true"></button>
                    </div>
                    <div class="modal-body">
                        <div id="selector__welcome-modal--simplesteps">
                            <p><?php eT("Some piece-of-cake steps to create your very own first survey:"); ?></p>
                            <div>
                                <ol>
                                    <li><?php echo sprintf(gT('Create a new survey by clicking on the %s icon.'), "<i class='ri-add-circle-fill text-success'></i>"); ?></li>
                                    <li><?php eT('Create a new question group inside your survey.'); ?></li>
                                    <li><?php eT('Create one or more questions inside the new question group.'); ?></li>
                                    <li><?php echo sprintf(gT('Done. Test your survey using the %s icon.'), "<i class='ri-settings-5-fill text-success'></i>"); ?></li>
                                </ol>
                            </div>
                            <div><hr /></div>

                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php eT('Close'); ?></button>
                        <a href="<?php echo $this->createUrl("surveyAdministration/newSurvey") ?>" class="btn btn-primary">
                            <?php eT('Create a new survey'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Rendering all boxes in database -->
    <?php if ($oldDashboard) : ?>
        <?php $this->widget('ext.PanelBoxWidget.PanelBoxWidget', [
            'display'          => 'allboxesinrows',
            'boxesbyrow'       => $iBoxesByRow,
            'offset'           => $sBoxesOffSet,
            'boxesincontainer' => $bBoxesInContainer
        ]); ?>
    <?php endif; ?>

    <div class="survey-dashboard">
        <?php if (empty(App()->request->getQuery('viewtype')) && empty(SettingsUser::getUserSettingValue('welcome_page_widget'))) : ?>
            <div class="col-12">
                <?php $this->widget('ext.admin.BoxesWidget.BoxesWidget', [
                    'switch' => true,
                    'items'  => [
                        [
                            'type'  => 0,
                            'model' => Survey::model(),
                            'limit' => 20,
                        ],
                    ]
                ]); ?>
            </div>
        <?php elseif (
            (!empty(App()->request->getQuery('viewtype')) && App()->request->getQuery('viewtype') === 'list-widget') ||
            (empty(App()->request->getQuery('viewtype')) && SettingsUser::getUserSettingValue('welcome_page_widget') === 'list-widget')
        ) : ?>
            <div class="col-12">
                <?php $this->widget('ext.admin.survey.ListSurveysWidget.ListSurveysWidget', [
                    'model' => $oSurveySearch,
                    'switch' => true
                ]); ?>
            </div>
        <?php else : ?>
            <div class="col-12">
                <?php $this->widget('ext.admin.BoxesWidget.BoxesWidget', [
                    'switch' => true,
                    'items'  => [
                        [
                            'type'  => 0,
                            'model' => Survey::model(),
                            'limit' => 20,
                        ],
                    ]
                ]); ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Notification setting -->
    <input type="hidden" id="absolute_notification" />
</div>
