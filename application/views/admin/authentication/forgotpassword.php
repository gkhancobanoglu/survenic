<?php
/**
 * Forgot your password
 */
?>

<noscript>
    <p><?php eT("LimeSurvey requires JavaScript to work properly."); ?></p>
</noscript>

<div class="login">
    <div class="row main-body">

        <!-- Sidebar (Logo) -->
        <div class="col-lg-6 sidebar-l d-none d-lg-flex">
            <div class="box-left w-100 d-flex flex-column justify-content-center align-items-center px-4">
                <div class="logo mb-4">
                    <img src="<?= Yii::app()->baseUrl ?>/assets/images/survenic-logo.png" alt="Survenic Logo" style="max-width: 300px;">
                </div>
            </div>
        </div>

        <!-- Forgot Password Panel -->
        <div class="col-12 col-lg-6 col-right">
            <div class="login-panel">
                <h1 class="text-center mb-3" style="font-size: 2.8rem; font-weight: 700;">Survenic</h1>
                <p class="text-center text-muted"><?php eT("Recover your password"); ?></p>

                <!-- Form -->
                <?php
                echo CHtml::form(
                    array("admin/authentication/sa/forgotpassword"),
                    'post',
                    array('id' => 'forgotpassword', 'name' => 'forgotpassword')
                ); ?>
                <div class="login-content-form">
                    <?php
                    $this->widget('ext.AlertWidget.AlertWidget', [
                        'text' =>  gT('To receive a new password by email you have to enter your user name and original email address.'),
                        'type' => 'info',
                    ]);
                    ?>
                    <div class="mb-3">
                        <label for="user"><?php eT('Username'); ?></label>
                        <input name="user" id="user" type="text" class="form-control ls-important-field" maxlength="64" />
                    </div>
                    <div class="mb-3">
                        <label for="email"><?php eT('Email address'); ?></label>
                        <input name="email" id="email" type="email" class="form-control ls-important-field" maxlength="254" />
                    </div>
                </div>

                <!-- Submit -->
                <div class="login-submit mt-4">
                    <input type="hidden" name="action" value="forgotpass" />
                    <button type="submit" class="btn btn-primary w-100 py-2">
                        <?php eT('Check data'); ?>
                    </button>
                    <div class="text-center mt-3">
                        <a href="<?php echo $this->createUrl("/admin"); ?>" style="color: #28a745; font-weight: 500;">
                            <?php eT('Main Admin Screen'); ?>
                        </a>
                    </div>
                </div>
                <?php echo CHtml::endForm(); ?>
            </div>
        </div>
    </div>
</div>
