<?php
/**
 * This view generate the 'overview' tab inside global settings.
 *
 * @var int $usercount
 * @var int $surveycount
 * @var int $activesurveycount
 * @var int $deactivatedsurveys
 * @var int $activetokens
 * @var int $deactivatedtokens
 * @var int TODO: seems to be deprecated see modules/admin/globalsettings/views/_overview.php
 */
?>
<br /><table class='table table-hover'>
    <tr><th><?php eT("Users"); ?></th><td><?php echo $usercount; ?></td></tr>
    <tr><th><?php eT("Surveys"); ?></th><td><?php echo $surveycount; ?></td></tr>
    <tr><th><?php eT("Active surveys"); ?></th><td><?php echo $activesurveycount; ?></td></tr>
    <tr><th><?php eT("Deactivated result tables"); ?></th><td><?php echo $deactivatedsurveys; ?></td></tr>
    <tr><th><?php eT("Active survey participants tables"); ?></th><td><?php echo $activetokens; ?></td></tr>
    <tr><th><?php eT("Deactivated survey participants tables"); ?></th><td><?php echo $deactivatedtokens; ?></td></tr>
    <?php if (Yii::app()->getConfig('iFileUploadTotalSpaceMB') > 0):
        $fUsed = calculateTotalFileUploadUsage(); ?>
        <tr>
            <th><?php eT("Used/free space for file uploads"); ?></th>
            <td>
                <?php echo number_format($fUsed, 2); ?> MB /
                <?php echo number_format(Yii::app()->getConfig('iFileUploadTotalSpaceMB') - $fUsed, 2); ?> MB
            </td>
        </tr>
    <?php endif; ?>
</table>


