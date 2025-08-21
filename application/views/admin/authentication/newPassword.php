<?php
/**
 * Set Password Form - Survenic Clean Layout
 */

// DO NOT REMOVE This is for automated testing
echo viewHelper::getViewTestTag('login');
?>

<noscript>
    <p><?php eT("Survenic requires JavaScript to work properly."); ?></p>
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

        <!-- Form Panel -->
        <div class="col-12 col-lg-6 col-right">
            <div class="login-panel">
                <h1 class="text-center mb-3" style="font-size: 2.8rem; font-weight: 700;">Survenic</h1>
                <p class="text-center text-muted"><?php eT("Set your new password"); ?></p>

                <?php if ($errorExists): ?>
                    <div class="alert alert-danger text-center mt-2"><?= $errorMsg ?></div>
                <?php else: ?>

                    <?php echo CHtml::form(['admin/authentication/sa/newPassword'], 'post', ['id' => 'loginform']); ?>
                    <div class="login-content-form">

                        <!-- Password -->
                        <div class="mb-3">
                            <label for="password" class="form-label required"><?= gT("Password") ?> <span class="text-danger">*</span></label>
                            <input name="password" id="password" placeholder="********" class="form-control ls-important-field" type="password">
                        </div>

                        <!-- Repeat Password -->
                        <div class="mb-3">
                            <label for="password_repeat" class="form-label required"><?= gT("Repeat password") ?> <span class="text-danger">*</span></label>
                            <input name="password_repeat" id="password_repeat" placeholder="********" class="form-control ls-important-field" type="password">
                        </div>

                        <!-- Suggested Password -->
                        <div class="mb-3">
                            <label class="form-label"><?= gT('Random password (suggestion):') ?></label>
                            <input type="text" class="form-control" readonly name="random_example_password" value="<?= htmlspecialchars((string)$randomPassword) ?>">
                        </div>

                        <input type="hidden" name="validation_key" value="<?= CHtml::encode($validationKey) ?>">

                        <!-- Submit -->
                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary w-100 py-2" name="login_submit" value="login" style="font-size: 1.1rem;">
                                <?php eT("Save password"); ?>
                            </button>
                        </div>

                    </div>
                    <?php echo CHtml::endForm(); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
