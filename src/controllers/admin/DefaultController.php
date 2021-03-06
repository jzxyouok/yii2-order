<?php

namespace nullref\order\controllers\admin;

use app\components\PriceCalculator;
use nullref\order\interfaces\Offer;
use nullref\order\models\OfferManager;
use nullref\order\models\OrderItem;
use nullref\order\models\OrderSearch;
use nullref\useful\actions\EditAction;
use Yii;
use nullref\order\models\Order;
use yii\data\ActiveDataProvider;
use yii\data\ArrayDataProvider;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use nullref\core\interfaces\IAdminController;

/**
 * DefaultController implements the CRUD actions for Order model.
 */
class DefaultController extends Controller implements IAdminController
{
    protected $offerManager;

    /**
     * DefaultController constructor.
     * Set OfferManager from DI container
     * @param string $id
     * @param \yii\base\Module $module
     * @param OfferManager $offerManager
     */
    public function __construct($id, $module, OfferManager $offerManager)
    {
        $this->offerManager = $offerManager;
        parent::__construct($id, $module);
    }

    /**
     * @return array
     */
    public function actions()
    {
        return array_merge(parent::actions(), [
            'edit-item' => [
                'class' => EditAction::className(),
                'findModel' => [$this, 'findOrderItem'],
            ],
        ]);
    }

    /**
     * @param $id
     * @return OrderItem|null|static
     */
    public function findOrderItem($id)
    {
        $order = $this->getOrder(Yii::$app->request->getQueryParam('order_id'));
        return $order->getItem($id);
    }

    /**
     * @param $orderId
     * @return Order
     * @throws NotFoundHttpException
     */
    protected function getOrder($orderId)
    {
        if ($orderId) {
            $model = $this->findModel($orderId);
            return $model;
        } else {
            $model = $this->getNewOrder();
            return $model;
        }
    }

    /**
     * Finds the Order model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return Order the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id)
    {
        if (($model = Order::findOne($id)) !== null) {
            return $model;
        } else {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
    }

    /**
     * @param bool $clear
     * @return Order|mixed
     */
    protected function getNewOrder($clear = false)
    {
        if ($clear) {
            $model = new Order();
            $this->updateOrder($model);
            return $model;
        }

        return Order::getFromSession('newOrder');
    }

    /**
     * @param $model
     */
    protected function updateOrder(Order $model)
    {
        if ($model->isNewRecord) {
            $model->saveToSession('newOrder');
        } else {
            $model->save();
        }
    }

    /**
     * @param \yii\base\Action $action
     * @param mixed $result
     * @return mixed
     */
    public function afterAction($action, $result)
    {
        if ($action->id === 'edit-item' && $result === null) {
            /** @var EditAction $action */
            /** @var OrderItem $orderItem */
            $orderItem = $action->model;
            $this->updateOrder($orderItem->order);
        }
        return parent::afterAction($action, $result); // TODO: Change the autogenerated stub
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'delete' => ['POST'],
                ],
            ],
        ];
    }

    /**
     * Lists all Order models.
     * @return mixed
     */
    public function actionIndex()
    {
        $searchModel = new OrderSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);
        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * Displays a single Order model.
     * @param integer $id
     * @return mixed
     */
    public function actionView($id)
    {
        return $this->render('view', [
            'model' => $this->findModel($id),
        ]);
    }

    /**
     * Updates an existing Order model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     */
    public function actionUpdate($id)
    {
        $model = $this->findModel($id);
        $itemsDataProvider = $this->getItemsProvider($model);

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', Yii::t('order', 'Order was updated'));
        }
        return $this->render('update', [
            'model' => $model,
            'itemsDataProvider' => $itemsDataProvider,
        ]);
    }

    /**
     * @param Order $model
     * @return ArrayDataProvider
     */
    protected function getItemsProvider($model)
    {
        return new ArrayDataProvider(['allModels' => $model->getItems(), 'pagination' => false]);
    }

    /**
     * Deletes an existing Order model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     */
    public function actionDelete($id)
    {
        $this->findModel($id)->delete();

        return $this->redirect(['index']);
    }

    /**
     * @param integer $order_id
     * @return string
     */
    public function actionOfferSearch($order_id)
    {
        $orderId = (int)$order_id;

        $dataProvider = $this->offerManager->getOfferDataProvider(Yii::$app->request->queryParams);

        $searchModel = $this->offerManager->getSearchModel();

        return $this->renderPartial('offer-search', [
            'dataProvider' => $dataProvider,
            'searchModel' => $searchModel,
            'orderId' => $orderId,
        ]);
    }

    /**
     * Add offer to order by id
     * @param $offer_id
     * @param int $order_id
     * @param int $amount
     * @return mixed
     * @throws NotFoundHttpException
     */
    public function actionAddItem($offer_id, $order_id = 0, $amount = 1)
    {
        $model = $this->getOrder($order_id);

        $offer = $this->findOffer($offer_id);

        /** @var PriceCalculator $priceCalculator */
        $priceCalculator = Yii::$app->get('priceCalculator');

        $orderItem = Order::createItem();

        $priceCalculator->withExtraRate(function () use ($orderItem, $offer) {
            $orderItem->setOffer($offer);
        }, $model->getExtraRate());
        $orderItem->amount = $amount;

        $model->addItem($orderItem);

        $this->updateOrder($model);

        return $this->actionItems($order_id);
    }

    /**
     * Find offer by id
     * @param $id
     * @return Offer
     * @throws NotFoundHttpException
     */
    protected function findOffer($id)
    {
        if (($model = $this->offerManager->findOfferById($id)) !== null) {
            return $model;
        }
        throw new NotFoundHttpException('The requested page does not exist.');

    }

    /**
     * @param $order_id
     * @return string
     */
    public function actionItems($order_id)
    {
        $model = $this->getOrder($order_id);

        $itemsDataProvider = $this->getItemsProvider($model);
        if (Yii::$app->request->isAjax) {
            return $this->renderAjax('_items', [
                'model' => $model,
                'itemsDataProvider' => $itemsDataProvider,
            ]);
        }
        return $this->render('_items', [
            'model' => $model,
            'itemsDataProvider' => $itemsDataProvider,
        ]);
    }

    /**
     * @param $item_id
     * @param int $order_id
     * @return string
     * @throws NotFoundHttpException
     */
    public function actionDeleteItem($item_id, $order_id = 0)
    {
        $model = $this->getOrder($order_id);

        $item = $model->getItem($item_id);

        $model->removeItem($item);

        $this->updateOrder($model);

        return $this->actionItems($order_id);
    }

    /**
     * Creates a new Order model.
     * If creation is successful, the browser will be redirected to the 'view' page.
     * @return mixed
     */
    public function actionCreate()
    {
        $request = Yii::$app->request;
        $model = $this->getNewOrder(!$request->isAjax && !$request->isPost);

        if ($model->load($request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', Yii::t('order', 'Order was created'));
        }
        $itemsDataProvider = $this->getItemsProvider($model);

        return $this->render('create', [
            'itemsDataProvider' => $itemsDataProvider,
            'model' => $model,
        ]);

    }

    /**
     * @return string
     * @throws \Exception
     */
    public function actionUtilities()
    {
        if (Yii::$app->request->getQueryParam('drop-all-orders')) {
            $orders = Order::find()->all();
            foreach ($orders as $order) {
                $order->delete();
            }
            Yii::$app->session->setFlash('success', Yii::t('order', 'Orders clear'));
        }
        return $this->render('utilities');
    }
}