<?php
declare(strict_types=1);

use app\models\AdOffer;
use yii\web\View;
use app\models\Currency;

/**
 * @var View $this
 * @var AdOffer $model
 * @var Currency[] $currencies
 * @var array $_params_
 */

$this->title = Yii::t('app', 'Create Offer');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Offers'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;

?>
<div class="ad-offer-create">

    <?= $this->render('_form', $_params_); ?>

</div>
