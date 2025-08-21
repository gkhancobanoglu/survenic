<?php
/**
 * Configuration menu (role-based, modern UI with embedded CSS)
 */
$hasThemePermission = Permission::model()->hasGlobalPermission('templates', 'read');
$hasLabelPermission = Permission::model()->hasGlobalPermission('labelsets', 'read') || Permission::model()->hasGlobalPermission('labelsets', 'create');
$hasUserPermission = Permission::model()->hasGlobalPermission('users', 'read') || Permission::model()->hasGlobalPermission('usergroups', 'read');
$hasParticipantPermission = Permission::model()->hasGlobalPermission('participantpanel', 'read') || Permission::model()->hasGlobalPermission('participantpanel', 'create') || Permission::model()->hasGlobalPermission('participantpanel', 'update') || Permission::model()->hasGlobalPermission('participantpanel', 'delete') || ParticipantShare::model()->exists('share_uid = :userid', [':userid' => App()->user->id]);
$hasSettingsPermission = Permission::model()->hasGlobalPermission('settings', 'read');
$hasSuperAdmin = Permission::model()->hasGlobalPermission('superadmin', 'read');
$showConfig = $hasThemePermission || $hasLabelPermission || $hasUserPermission || $hasParticipantPermission || $hasSettingsPermission || $hasSuperAdmin;
?>

<?php if ($showConfig): ?>
    <style>
        /* Survenic modern config menu style */
        .config-box {
            background-color: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            text-align: center;
            transition: all 0.3s ease-in-out;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            height: 100%;
        }

        .config-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .config-box .icon {
            color: #6c757d;
        }

        .config-box h6 {
            font-size: 1.05rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }

        .config-box ul li {
            margin: 4px 0;
        }

        .config-box ul li a {
            text-decoration: none;
            color: #198754;
            font-weight: 500;
        }

        .config-box ul li a:hover {
            text-decoration: underline;
            color: #145c32;
        }

        .config-box dl dt {
            font-weight: 500;
        }

        .config-box dl dd {
            font-weight: 400;
        }

        /* Responsive spacing */
        #mainmenu-dropdown .col-md-2,
        #mainmenu-dropdown .col-md-3 {
            min-width: 200px;
        }
    </style>

    <li class="dropdown mega-dropdown nav-item">
        <a href="#" class="nav-link dropdown-toggle mainmenu-dropdown-toggle" data-bs-toggle="dropdown">
            <?php eT('Configuration'); ?>
            <span class="caret"></span>
        </a>
        <div class="dropdown-menu mega-dropdown-menu p-4" id="mainmenu-dropdown">
            <div class="row g-4 justify-content-start">

                <?php if ($hasSuperAdmin): ?>
                    <div class="col-md-3">
                        <div class="config-box text-center p-3">
                            <div class="icon mb-2"><i class="ri-information-fill fs-3"></i></div>
                            <h6 class="fw-bold"><?php eT("System overview"); ?></h6>
                            <dl class="mb-0 mt-2 small text-muted">
                                <div class="d-flex justify-content-between"><dt><?php eT('Users'); ?></dt><dd><?php echo $userscount; ?></dd></div>
                                <div class="d-flex justify-content-between"><dt><?php eT('Surveys'); ?></dt><dd><?php echo $surveyscount; ?></dd></div>
                                <div class="d-flex justify-content-between"><dt><?php eT('Active surveys'); ?></dt><dd><?php echo $activesurveyscount; ?></dd></div>
                            </dl>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($hasThemePermission || $hasLabelPermission || $hasSuperAdmin): ?>
                    <div class="col-md-2">
                        <div class="config-box text-center p-3">
                            <div class="icon mb-2"><i class="ri-tools-fill fs-3"></i></div>
                            <h6 class="fw-bold"><?php eT("Advanced"); ?></h6>
                            <ul class="list-unstyled mt-2 mb-0 small">
                                <?php if ($hasThemePermission): ?>
                                    <li><a href="<?= $this->createUrl("themeOptions/index"); ?>"><?php eT("Themes"); ?></a></li>
                                <?php endif; ?>
                                <?php if ($hasLabelPermission): ?>
                                    <li><a href="<?= $this->createUrl("admin/labels/sa/view"); ?>"><?php eT("Label sets"); ?></a></li>
                                <?php endif; ?>
                                <?php if ($hasSuperAdmin): ?>
                                    <li><a href="<?= $this->createUrl("admin/checkintegrity"); ?>"><?php eT("Data integrity"); ?></a></li>
                                    <li><a href="<?= $this->createUrl("admin/dumpdb"); ?>"><?php eT("Backup entire database"); ?></a></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($hasUserPermission || $hasParticipantPermission): ?>
                    <div class="col-md-2">
                        <div class="config-box text-center p-3">
                            <div class="icon mb-2"><i class="ri-user-fill fs-3"></i></div>
                            <h6 class="fw-bold"><?php eT("Users"); ?></h6>
                            <ul class="list-unstyled mt-2 mb-0 small">
                                <?php if (Permission::model()->hasGlobalPermission('users', 'read')): ?>
                                    <li><a href="<?= $this->createUrl("userManagement/index"); ?>"><?php eT("User management"); ?></a></li>
                                <?php endif; ?>
                                <?php if (Permission::model()->hasGlobalPermission('usergroups', 'read')): ?>
                                    <li><a href="<?= $this->createUrl("userGroup/index"); ?>"><?php eT("User groups"); ?></a></li>
                                <?php endif; ?>
                                <?php if ($hasSuperAdmin): ?>
                                    <li><a href="<?= $this->createUrl("userRole/index"); ?>"><?php eT("User roles"); ?></a></li>
                                <?php endif; ?>
                                <?php if ($hasParticipantPermission): ?>
                                    <li><a href="<?= $this->createUrl("admin/participants/sa/displayParticipants"); ?>"><?php eT("Central participant management"); ?></a></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($hasSettingsPermission): ?>
                    <div class="col-md-2">
                        <div class="config-box text-center p-3">
                            <div class="icon mb-2"><i class="ri-settings-3-line fs-3"></i></div>
                            <h6 class="fw-bold"><?php eT("Settings"); ?></h6>
                            <ul class="list-unstyled mt-2 mb-0 small">
                                <li><a href="<?= $this->createUrl("homepageSettings/index"); ?>"><?php eT("Dashboard"); ?></a></li>
                                <li><a href="<?= $this->createUrl("admin/globalsettings"); ?>"><?php eT("Global"); ?></a></li>
                                <li><a href="<?= $this->createUrl("admin/globalsettings/sa/surveysettings"); ?>"><?php eT("Global survey"); ?></a></li>
                                <li><a href="<?= $this->createUrl("/admin/pluginmanager/sa/index"); ?>"><?php eT("Plugins"); ?></a></li>
                                <li><a href="<?= $this->createUrl("admin/menus/sa/view"); ?>"><?php eT("Survey menus"); ?></a></li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </li>
<?php endif; ?>
