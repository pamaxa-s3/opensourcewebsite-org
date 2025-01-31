<?php
declare(strict_types=1);

use app\components\helpers\Html;
use app\models\Company;
use app\models\search\ResumeSearch;
use app\widgets\buttons\AddButton;
use yii\data\ActiveDataProvider;
use yii\grid\ActionColumn;
use yii\grid\GridView;
use yii\helpers\Url;
use yii\web\View;

/**
 * @var View $this
 * @var ActiveDataProvider $dataProvider
 * @var ResumeSearch $searchModel
 */

$this->title = Yii::t('app', 'Companies');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="index">
    <div class="row">
        <div class="col-12">
            <div class="card table-overflow">
                <div class="card-header d-flex p-0">
                    <ul class="nav nav-pills ml-auto p-2">
                        <li class="nav-item align-self-center mr-4">
                            <?= AddButton::widget([
                                'url' => ['company-user/create'],
                                'options' => [
                                    'title' => 'New Company',
                                ]
                            ]); ?>
                        </li>
                    </ul>
                </div>
                <div class="card-body p-0">
                    <?= GridView::widget([
                        'dataProvider' => $dataProvider,
                        //'filterModel' => $searchModel,
                        'summary' => false,
                        'tableOptions' => ['class' => 'table table-hover'],
                        'columns' => [
                            [
                                'attribute' => 'id',
                                'enableSorting' => false,
                            ],
                            [
                                'attribute' => 'name',
                                'enableSorting' => false,
                            ],
                            [
                                'label' => Yii::t('app', 'Vacancies'),
                                'content' => function (Company $model) {
                                    if (($vacanciesCount = $model->getVacancies()->count()) > 0) {
                                        return Html::a(
                                            (string)$vacanciesCount,
                                            Url::to([
                                                '/vacancy/index',
                                                'VacancySearch[company_id]' => (string)$model->id
                                            ])
                                        );
                                    }
                                }
                            ],
                            [
                                'class' => ActionColumn::class,
                                'template' => '{view}',
                                'buttons' => [
                                    'view' => function ($url) {
                                        return Html::a(Html::icon('eye'), $url, ['class' => 'btn btn-outline-primary']);
                                    },
                                ]
                            ],
                        ],

                        'layout' => "{summary}\n{items}\n<div class='card-footer clearfix'>{pager}</div>",
                        'pager' => [
                            'options' => [
                                'class' => 'pagination float-right',
                            ],
                            'linkContainerOptions' => [
                                'class' => 'page-item',
                            ],
                            'linkOptions' => [
                                'class' => 'page-link',
                            ],
                            'maxButtonCount' => 5,
                            'disabledListItemSubTagOptions' => [
                                'tag' => 'a',
                                'class' => 'page-link',
                            ],
                        ],
                    ]); ?>
                </div>
            </div>
        </div>
    </div>
</div>
