<?php
/**
 * Login Form - Survenic Clean Layout with language auto-support
 */
echo viewHelper::getViewTestTag('login');
?>

<noscript>
    <p><?php eT("Survenic requires JavaScript to work properly."); ?></p>
</noscript>

<div class="login">
    <div class="row main-body">
        <!-- Sidebar (Logo only) -->
        <div class="col-lg-6 sidebar-l d-none d-lg-flex">
            <div class="box-left w-100 d-flex flex-column justify-content-center align-items-center px-4">
                <div class="logo mb-4">
                    <img src="<?= Yii::app()->baseUrl ?>/assets/images/survenic-logo.png" alt="Survenic Logo" style="max-width: 300px;">
                </div>
            </div>
        </div>

        <!-- Login Panel -->
        <div class="col-12 col-lg-6 col-right">
            <div class="login-panel">
                <h1 class="text-center mb-3" style="font-size: 2.8rem; font-weight: 700;">Survenic</h1>
                <p class="text-center text-muted"><?php eT("Log in"); ?></p>

                <!-- Login Form -->
                <?php echo CHtml::form(['admin/authentication/sa/login'], 'post', ['id' => 'loginform']); ?>
                <div class="login-content-form">
                    <?php
                    $pluginNames = array_keys($pluginContent);
                    if (!isset($defaultAuth)) {
                        $defaultAuth = reset($pluginNames);
                    }

                    if (count($pluginContent) > 1) {
                        $selectedAuth = App()->getRequest()->getParam('authMethod', $defaultAuth);
                        if (!in_array($selectedAuth, $pluginNames)) {
                            $selectedAuth = $defaultAuth;
                        }

                        echo "<label for='authMethod'>" . gT("Authentication method") . "</label>";

                        $possibleAuthMethods = [];
                        foreach ($pluginNames as $plugin) {
                            $info = App()->getPluginManager()->getPluginInfo($plugin);
                            $methodName = call_user_func([$info['pluginClass'], 'getAuthMethodName']);
                            $possibleAuthMethods[$plugin] = !empty($methodName) ? $methodName : $info['pluginName'];
                        }

                        $this->widget('yiiwheels.widgets.select2.WhSelect2', [
                            'name' => 'authMethod',
                            'data' => $possibleAuthMethods,
                            'value' => $selectedAuth,
                            'pluginOptions' => [
                                'options' => ['onChange' => 'this.form.submit();']
                            ]
                        ]);
                    } else {
                        echo CHtml::hiddenField('authMethod', $defaultAuth);
                        $selectedAuth = $defaultAuth;
                    }

                    if (isset($pluginContent[$selectedAuth])) {
                        echo $pluginContent[$selectedAuth]->getContent();
                    }

                    if (Yii::app()->getConfig("demoMode") === true && Yii::app()->getConfig("demoModePrefill") === true) {
                        echo "<p class='text-info text-center mt-2'>" . gT("Demo mode: Login credentials are prefilled - just click the Login button.") . "</p>";
                    }
                    ?>
                </div>

                <!-- Submit -->
                <div class="login-submit mt-4">
                    <input type="hidden" name="action" value="login" />
                    <input type="hidden" id="width" name="width" value="" />
                    <button type="submit" class="btn btn-primary w-100 py-2" name="login_submit" value="login" style="font-size: 1.1rem;">
                        <?php eT("Log in"); ?>
                    </button>

                    <?php if (Yii::app()->getConfig("display_user_password_in_email") === true): ?>
                        <div class="forgot text-center mt-3">
                            <a href="<?= $this->createUrl("admin/authentication/sa/forgotpassword"); ?>" style="color: #28a745; font-weight: 500;">
                                <?php eT("Forgot your password?"); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <?php echo CHtml::endForm(); ?>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
    $(document).ready(function () {
        $('#user').focus();
        $("#width").val($(window).width());
    });
    $(window).resize(function () {
        $("#width").val($(window).width());
    });
</script>
