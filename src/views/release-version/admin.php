<?php
/* @var $model ReleaseVersion */
use yii\helpers\Html;
$this->params['menu']=array(
	array('label'=>'List ReleaseVersion', 'url'=>array('index')),
	array('label'=>'Create ReleaseVersion', 'url'=>array('create')),
);

$this->registerJs("
$('.search-button').click(function(){
	$('.search-form').toggle();
	return false;
});
$('.search-form form').submit(function(){
	$('#release-version-grid').yiiGridView('update', {
		data: $(this).serialize()
	});
	return false;
});
", $this::POS_READY, 'search');
?>

<h1>Manage Release Versions</h1>

<p>
You may optionally enter a comparison operator (<b>&lt;</b>, <b>&lt;=</b>, <b>&gt;</b>, <b>&gt;=</b>, <b>&lt;&gt;</b>
or <b>=</b>) at the beginning of each of your search values to specify how the comparison should be done.
</p>

<?php echo Html::a('Advanced Search','#',array('class'=>'search-button')); ?>
<div class="search-form" style="display:none">
<?php echo $this->render('_search',array(
	'model'=>$model,
)); ?>
</div><!-- search-form -->

<?php $this->widget('yiistrap.widgets.TbGridView', array(
	'id'=>'release-version-grid',
	'htmlOptions' => ['class' => 'table-responsive'],
	'dataProvider'=>$model->search(),
	'filter'=>$model,
	'columns'=>array(
		'obj_created',
		'rv_version',
		'rv_name',
		array(
            'class'=>'yiistrap.widgets.TbButtonColumn',
		),
	),
)); ?>
