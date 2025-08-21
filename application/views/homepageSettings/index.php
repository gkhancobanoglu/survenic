<?php
/* @var AdminController $this */
/* @var CActiveDataProvider $dataProvider */

// DO NOT REMOVE This is for automated testing to validate we see that page
echo viewHelper::getViewTestTag('homepageSettings');
?>

<div class="row">
    <div class="col-12 list-surveys">
        <!-- Tabs -->
        <ul class="nav nav-tabs" id="boxeslist">
            <li class="nav-item">
                <a class="nav-link active" href='#boxes' data-bs-toggle="tab">
                    <?php eT('Buttons') ?>
                </a>
            </li>
        </ul>
        <div class="tab-content">
            <!-- Boxes -->
            <div id="boxes" class="tab-pane fade show active">
                <?php $this->widget(
                    'application.extensions.admin.grid.CLSGridView',
                    [
                        'id' => 'boxes-grid',
                        'dataProvider' => $dataProviderBox->search(),
                        'pager' => [
                            'class' => 'application.extensions.admin.grid.CLSYiiPager',
                        ],
                        'summaryText' => gT('Displaying {start}-{end} of {count} result(s).') . ' '
                            . sprintf(
                                gT('%s rows per page'),
                                CHtml::dropDownList(
                                    'boxes-pageSize',
                                    Yii::app()->user->getState('pageSize', Yii::app()->params['defaultPageSize']),
                                    Yii::app()->params['pageSizeOptions'],
                                    ['class' => 'changePageSize form-select', 'style' => 'display: inline; width: auto']
                                )
                            ),
                        'columns' => [
                            [
                                'header' => gT('Position'),
                                'name' => 'position',
                                'value' => '$data->position',
                                'htmlOptions' => ['class' => ''],
                            ],
                            [
                                'header' => gT('Title'),
                                'name' => 'title',
                                'value' => '$data->title',
                                'htmlOptions' => ['class' => ''],
                            ],
                            [
                                'header' => gT('Icon'),
                                'name' => 'icon',
                                'value' => '$data->getSpanIcon()',
                                'type' => 'raw',
                                'htmlOptions' => ['class' => ''],
                            ],
                            [
                                'header' => gT('Description'),
                                'name' => 'desc',
                                'value' => '$data->desc',
                                'htmlOptions' => ['class' => ''],
                            ],
                            [
                                'header' => gT('URL'),
                                'name' => 'url',
                                'value' => '$data->url',
                                'htmlOptions' => ['class' => ''],
                            ],
                            [
                                'header' => gT('User group'),
                                'name' => 'url',
                                'value' => '$data->usergroupname',
                                'htmlOptions' => ['class' => ''],
                            ],
                            [
                                'header' => gT('Action'),
                                'name' => 'actions',
                                'value' => '$data->buttons',
                                'type' => 'raw',
                                'headerHtmlOptions' => ['class' => 'ls-sticky-column'],
                                'htmlOptions'       => ['class' => 'text-center button-column ls-sticky-column'],
                            ],
                        ],
                    ]
                ); ?>
            </div>
        </div>
    </div>
</div>

<script>
    $('#boxeslist a').click(function (e) {
        window.location.hash = $(this).attr('href');
        let tabName = $(this).tab().attr('href');
        if (tabName === '#boxes') {
            $('#save_boxes_setting').hide();
        } else {
            $('#save_boxes_setting').show();
        }
    });

    $(document).on('ready pjax:scriptcomplete', function () {
        $('#save_boxes_setting').hide();
        if (window.location.hash) {
            $('#boxeslist').find('a[href=' + window.location.hash + ']').trigger('click');
        }
    });
</script>

<script type="text/javascript">
    jQuery(function ($) {
        $(document).on("change", '#boxes-pageSize', function () {
            $.fn.yiiGridView.update('boxes-grid', {data: {pageSize: $(this).val()}});
        });
    });
</script>
