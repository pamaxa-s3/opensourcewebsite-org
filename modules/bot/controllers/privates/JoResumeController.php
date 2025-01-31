<?php

namespace app\modules\bot\controllers\privates;

use app\behaviors\SetAttributeValueBehavior;
use app\behaviors\SetDefaultCurrencyBehavior;
use app\models\Currency;
use app\models\JobKeyword;
use app\models\JobResumeKeyword;
use app\models\JobResumeMatch;
use app\models\Resume;
use app\models\scenarios\Resume\SetActiveScenario;
use app\models\User;
use app\modules\bot\components\crud\CrudController;
use app\modules\bot\components\crud\rules\ExplodeStringFieldComponent;
use app\modules\bot\components\crud\rules\LocationToArrayFieldComponent;
use app\modules\bot\components\helpers\Emoji;
use app\modules\bot\components\helpers\ExternalLink;
use app\modules\bot\components\helpers\ListButtons;
use app\modules\bot\components\helpers\PaginationButtons;
use app\modules\bot\models\User as TelegramUser;
use Yii;
use yii\data\Pagination;
use yii\db\ActiveRecord;
use yii\db\StaleObjectException;

/**
 * Class JoResumeController
 *
 * @link https://opensourcewebsite.org/resume
 * @package app\modules\bot\controllers\privates
 */
class JoResumeController extends CrudController
{
    protected $updateAttributes = [
        'name',
        'skills',
        'experiences',
        'expectations',
        'keywords',
        'min_hourly_rate',
        'remote_on',
        'location',
        'search_radius',
    ];

    /**
     * {@inheritdoc}
     */
    protected function rules()
    {
        return [
            'model' => Resume::class,
            'prepareViewParams' => function ($params) {
                /** @var Resume $model */
                $model = $params['model'] ?? null;

                return [
                    'model' => $model,
                    'keywords' => self::getKeywordsAsString($model->getKeywords()->all()),
                    'locationLink' => ExternalLink::getOSMLink($model->location_lat, $model->location_lon),
                ];
            },
            'attributes' => [
                'name' => [],
                'skills' => [
                    'isRequired' => false,
                ],
                'experiences' => [
                    'isRequired' => false,
                ],
                'expectations' => [
                    'isRequired' => false,
                ],
                'keywords' => [
                    //'enableAddButton' = true,
                    'isRequired' => false,
                    'relation' => [
                        'model' => JobResumeKeyword::class,
                        'attributes' => [
                            'resume_id' => [Resume::class, 'id'],
                            'job_keyword_id' => [JobKeyword::class, 'id', 'keyword'],
                        ],
                        'removeOldRows' => true,
                    ],
                    'component' => [
                        'class' => ExplodeStringFieldComponent::class,
                        'attributes' => [
                            'delimiters' => [',', '.', "\n"],
                        ],
                    ],
                ],
                'currency' => [
                    'behaviors' => [
                        'SetDefaultCurrencyBehavior' => [
                            'class' => SetDefaultCurrencyBehavior::class,
                            'telegramUser' => $this->getTelegramUser(),
                            'attributes' => [
                                ActiveRecord::EVENT_BEFORE_VALIDATE => ['currency_id'],
                                ActiveRecord::EVENT_BEFORE_INSERT => ['currency_id'],
                            ],
                        ],
                    ],
                    'hidden' => true,
                    'relation' => [
                        'attributes' => [
                            'currency_id' => [Currency::class, 'id', 'code'],
                        ],
                    ],
                ],
                'min_hourly_rate' => [
                    'isRequired' => false,
                    'buttons' => [
                        [
                            'text' => Yii::t('bot', 'Edit currency'),
                            'item' => 'currency',
                        ],
                    ],
                ],
                'remote_on' => [
                    'buttons' => [
                        [
                            'text' => Yii::t('bot', 'YES'),
                            'callback' => function (Resume $model) {
                                $model->remote_on = Resume::REMOTE_ON;

                                return $model;
                            },
                        ],
                        [
                            'text' => Yii::t('bot', 'NO'),
                            'callback' => function (Resume $model) {
                                $model->remote_on = Resume::REMOTE_OFF;

                                return $model;
                            },
                        ],
                    ],
                ],
                'location' => [
                    'isRequired' => false,
                    'component' => LocationToArrayFieldComponent::class,
                    'buttons' => [
                        [
                            //'hideCondition' => !$this->getTelegramUser()->location_lat || !$this->getTelegramUser()->location_lon,
                            'text' => Yii::t('bot', 'MY LOCATION'),
                            'callback' => function (Resume $model) {
                                $latitude = 0;//$this->getTelegramUser()->location_lat;
                                $longitude = 0;//$this->getTelegramUser()->location_lon;
                                if ($latitude && $longitude) {
                                    $model->location_lat = $latitude;
                                    $model->location_lon = $longitude;

                                    return $model;
                                }

                                return null;
                            },
                        ],
                    ],
                ],
                'search_radius' => [
                    'buttons' => [
                        [
                            'text' => Yii::t('bot', 'NO'),
                            'callback' => function (Resume $model) {
                                $model->search_radius = 0;

                                return $model;
                            },
                        ],
                    ],
                ],
                'user_id' => [
                    'behaviors' => [
                        'SetAttributeValueBehavior' => [
                            'class' => SetAttributeValueBehavior::class,
                            'attributes' => [
                                ActiveRecord::EVENT_BEFORE_VALIDATE => ['user_id'],
                                ActiveRecord::EVENT_BEFORE_INSERT => ['user_id'],
                            ],
                            'attribute' => 'user_id',
                            'value' => $this->module->user->id,
                        ],
                    ],
                    'hidden' => true,
                ],
            ],
        ];
    }

    /**
     * @param int $page
     *
     * @return array
     */
    public function actionIndex($page = 1)
    {
        $this->getState()->setName(null);

        $globalUser = $this->getUser();

        $query = $globalUser->getResumes()
            ->orderBy([
                'status' => SORT_DESC,
                'name' => SORT_ASC,
            ]);

        $pagination = new Pagination([
            'totalCount' => $query->count(),
            'pageSize' => 9,
            'params' => [
                'page' => $page,
            ],
            'pageSizeParam' => false,
            'validatePage' => true,
        ]);

        $paginationButtons = PaginationButtons::build($pagination, function ($page) {
            return self::createRoute('index', [
                'page' => $page,
            ]);
        });

        $buttons = [];

        $resumes = $query->offset($pagination->offset)
            ->limit($pagination->limit)
            ->all();

        if ($resumes) {
            foreach ($resumes as $resume) {
                $buttons[][] = [
                    'callback_data' => self::createRoute('view', [
                        'id' => $resume->id,
                    ]),
                    'text' => ($resume->isActive() ? '' : Emoji::INACTIVE . ' ') . '#' . $resume->id . ' ' . $resume->name,
                ];
            }

            if ($paginationButtons) {
                $buttons[] = $paginationButtons;
            }
        }

        $rowButtons[] = [
            'callback_data' => JoController::createRoute(),
            'text' => Emoji::BACK,
        ];

        $rowButtons[] = [
            'callback_data' => MenuController::createRoute(),
            'text' => Emoji::MENU,
        ];

        $matchesCount = JobResumeMatch::find()
            ->joinWith('resume')
            ->andWhere([
                Resume::tableName() . '.user_id' => $globalUser->id,
            ])
            ->count();

        if ($matchesCount) {
            $rowButtons[] = [
                'callback_data' => self::createRoute('all-matches'),
                'text' => Emoji::OFFERS . ' ' . $matchesCount,
                'visible' => YII_ENV_DEV,
            ];
        }

        $rowButtons[] = [
            'callback_data' => self::createRoute('create'),
            'text' => Emoji::ADD,
            'visible' => YII_ENV_DEV,
        ];

        $buttons[] = $rowButtons;

        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('index'),
                $buttons
            )
            ->build();
    }

    /**
     * @param ActiveRecord[] $keywords
     *
     * @return string
     */
    private static function getKeywordsAsString($keywords)
    {
        $resultKeywords = [];

        foreach ($keywords as $keyword) {
            $resultKeywords[] = $keyword->keyword;
        }

        return implode(', ', $resultKeywords);
    }

    /**
     * @param int $id Resume->id
     *
     * @return array
     */
    public function actionView($id = null)
    {
        $globalUser = $this->getUser();

        $resume = $globalUser->getResumes()
            ->where([
                'id' => $id,
            ])
            ->one();

        if (!isset($resume)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        $this->getState()->setName(null);

        $buttons[] = [
            [
                'callback_data' => self::createRoute('set-status', [
                    'id' => $resume->id,
                ]),
                'text' => $resume->isActive() ? Emoji::STATUS_ON . ' ON' : Emoji::STATUS_OFF . ' OFF',
            ],
        ];

        $matchesCount = $resume->getMatches()->count();

        if ($matchesCount) {
            $buttons[][] = [
                'callback_data' => self::createRoute('matches', [
                    'id' => $resume->id,
                ]),
                'text' => Emoji::OFFERS . ' ' . $matchesCount,
            ];
        }

        $buttons[] = [
            [
                'callback_data' => self::createRoute('index'),
                'text' => Emoji::BACK,
            ],
            [
                'callback_data' => MenuController::createRoute(),
                'text' => Emoji::MENU,
            ],
            [
                'callback_data' => self::createRoute('update', [
                    'id' => $resume->id,
                ]),
                'text' => Emoji::EDIT,
                'visible' => YII_ENV_DEV,
            ],
        ];

        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('view', [
                    'model' => $resume,
                    'keywords' => self::getKeywordsAsString($resume->getKeywords()->all()),
                ]),
                $buttons,
                [
                    'disablePreview' => true,
                ]
            )
            ->build();
    }

    /**
     * @param int $page
     * @param int $id Resume->id
     *
     * @return array
     */
    public function actionMatches($page = 1, $id = null)
    {
        $globalUser = $this->getUser();

        $resume = $globalUser->getResumes()
            ->where([
                'id' => $id,
            ])
            ->one();

        if (!isset($resume)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        $query = $resume->getMatchesOrderByRank();
        $matchesCount = $query->count();

        if (!$matchesCount) {
            return $this->actionView($resume->id);
        }

        $pagination = new Pagination([
            'totalCount' => $matchesCount,
            'pageSize' => 1,
            'params' => [
                'page' => $page,
            ],
            'pageSizeParam' => false,
            'validatePage' => true,
        ]);

        $vacancy = $query->offset($pagination->offset)
            ->limit($pagination->limit)
            ->one();

        if (!$vacancy) {
            return $this->actionView($resume->id);
        }

        $buttons[] = [
            [
                'callback_data' => self::createRoute('view', [
                    'id' => $resume->id,
                ]),
                'text' => '#' . $resume->id . ' ' . $resume->name,
            ]
        ];

        $buttons[] = PaginationButtons::build($pagination, function ($page) use ($resume) {
            return self::createRoute('matches', [
                'id' => $resume->id,
                'page' => $page,
            ]);
        });

        $buttons[] = [
            [
                'callback_data' => self::createRoute('view', [
                    'id' => $resume->id,
                ]),
                'text' => Emoji::BACK,
            ],
            [
                'callback_data' => MenuController::createRoute(),
                'text' => Emoji::MENU,
            ],
        ];

        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('match', [
                    'model' => $vacancy,
                    'company' => $vacancy->company,
                    'keywords' => self::getKeywordsAsString($vacancy->getKeywords()->all()),
                ]),
                $buttons,
                [
                    'disablePreview' => true,
                ]
            )
            ->build();
    }

    /**
     * {@inheritdoc}
     */
    public function actionAllMatches($page = 1)
    {
        $user = $this->getUser();

        $matchesQuery = JobResumeMatch::find()
            ->joinWith('resume')
            ->andWhere([
                Resume::tableName() . '.user_id' => $user->id,
            ]);

        $matchesCount = $matchesQuery->count();

        if (!$matchesCount) {
            return $this->actionIndex();
        }

        $pagination = new Pagination([
            'totalCount' => $matchesQuery->count(),
            'pageSize' => 1,
            'params' => [
                'page' => $page,
            ],
            'pageSizeParam' => false,
            'validatePage' => true,
        ]);

        $jobResumeMatch = $matchesQuery->offset($pagination->offset)
            ->limit($pagination->limit)
            ->one();
        $resume = $jobResumeMatch->resume;
        $vacancy = $jobResumeMatch->vacancy;

        $buttons[] = [
            [
                'text' => $resume->name,
                'callback_data' => self::createRoute('view', [
                    'id' => $resume->id,
                ]),
            ]
        ];

        $buttons[] = PaginationButtons::build($pagination, function ($page) {
            return self::createRoute('all-matches', [
                'page' => $page,
            ]);
        });

        $buttons[] = [
            [
                'callback_data' => self::createRoute('index'),
                'text' => Emoji::BACK,
            ],
            [
                'callback_data' => MenuController::createRoute(),
                'text' => Emoji::MENU,
            ],
        ];

        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('match', [
                    'model' => $vacancy,
                    'company' => $vacancy->company,
                    'keywords' => self::getKeywordsAsString($vacancy->getKeywords()->all()),
                    'locationLink' => ExternalLink::getOSMLink($vacancy->location_lat, $vacancy->location_lon),
                    'languages' => array_map(function ($vacancyLanguage) {
                        return $vacancyLanguage->getLabel();
                    }, $vacancy->vacancyLanguagesRelation),
                    'user' => TelegramUser::findOne(['user_id' => $vacancy->user_id]),
                ]),
                $buttons,
                [
                    'disablePreview' => true,
                ]
            )
            ->build();
    }

    /**
     * @param int $id
     */
    public function actionDelete($id)
    {
        $user = $this->getUser();

        $resume = $user->getResumes()
            ->where([
                'id' => $id,
            ])
            ->one();

        if (!isset($resume)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        $resume->delete();

        return $this->actionIndex();
    }

    /**
     * @param int $id Resume->id
     *
     * @return array
     */
    public function actionSetStatus($id = null)
    {
        $model = Resume::find()
            ->where([
                'id' => $id,
            ])
            ->userOwner()
            ->one();

        if (!isset($model)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        switch ($model->status) {
            case Resume::STATUS_ON:
                $model->setInactive();
                $model->save(false);

                break;
            case Resume::STATUS_OFF:
                $scenario = new SetActiveScenario($model);

                if ($scenario->run()) {
                    $model->save(false);
                } else {
                    return $this->getResponseBuilder()
                        ->answerCallbackQuery(
                            $this->render('../alert', [
                                'alert' => $scenario->getFirstError(),
                            ]),
                            true
                        )
                        ->build();
                }
        }

        return $this->actionView($model->id);
    }

    /**
     * @param integer $id
     *
     * @return Resume|ActiveRecord
     */
    protected function getModel($id)
    {
        return ($id == null) ? new Resume() : Resume::findOne(['id' => $id, 'user_id' => $this->getUser()->id]);
    }
}
