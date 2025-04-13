<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('memory_limit', '5G');
error_reporting(E_ALL);

$tmpstr = '';

require realpath(__DIR__) . '/../app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

$bootstrap->getObjectManager()->get('\Magento\Framework\App\State')->setAreaCode("frontend");

use Magento\Eav\Model\ResourceModel\Entity\Attribute;
use Magento\Framework\App\ResourceConnection;

class UpdateImageAlt
{
    const IMAGE_PATH = '<path>/images_alt_magento.csv';
    /**
     * @var Attribute
     */
    protected $eavAttribute;

    protected $resource;

    protected $connection;

    protected $productSalesData = [];

    public function __construct(
        Attribute $eavAttribute,
        ResourceConnection $resource
    ) {
        $this->eavAttribute = $eavAttribute;
        $this->resource = $resource;
        $this->connection = $this->resource->getConnection();
    }

    public function getAttributeFromCode($attributeCode)
    {
        return $this->eavAttribute->getIdByCode(
                \Magento\Catalog\Model\Product::ENTITY,
                $attributeCode
            );
    }

    public function getProductsFromSku($skus)
    {    
        $product = $this->connection->getTableName('catalog_product_entity');
        $select = $this->connection->select()
            ->from($product, ['product_id'=>'entity_id','sku','row_id'])
            ->where('sku IN (?) ', $skus);

        return $this->connection->fetchAll($select);
    }

    public function getProductsImage($rowIds, $attributeIds)
    {    
        $product = $this->connection->getTableName('catalog_product_entity_varchar');
        $select = $this->connection->select()
            ->from(['attribute'=>$product], ['attribute_id','value','attribute.row_id'])
            ->join(
                ['product' => $this->connection->getTableName('catalog_product_entity')],
                "attribute.row_id = product.row_id",
                ['sku']
            )->where('attribute.row_id IN (?) ', $rowIds)
            ->where('attribute.attribute_id IN (?) ', $attributeIds);
        $result = $this->connection->fetchAll($select);
        $dataArray = [];
        foreach ($result as $salesValue) {
            $dataArray[$salesValue['sku']][$salesValue['attribute_id']] = $salesValue;
        }
        return $dataArray;
    }

    public function getMediaGalleryImage($rowIds, $attributeId)
    {    
        $product = $this->connection->getTableName('catalog_product_entity_media_gallery_value');
        $select = $this->connection->select()
            ->from(['attribute'=>$product], ['value_id','row_id'])
            ->join(
                ['product' => $this->connection->getTableName('catalog_product_entity_media_gallery')],
                "attribute.value_id = product.value_id",
                ['value']
            )->join(
                ['entity' => $this->connection->getTableName('catalog_product_entity')],
                "attribute.row_id = entity.row_id",
                ['sku']
            )->where('attribute.row_id IN (?) ', $rowIds)
            ->where('product.attribute_id = ? ', $attributeId);
        $result = $this->connection->fetchAll($select);
        $dataArray = [];
        foreach ($result as $salesValue) {
            $dataArray[$salesValue['sku']][$salesValue['value_id']] = $salesValue;
        }
        return $dataArray;
    }

    public function readCsvData()
    {    
        $csv = array_map(function($v){return str_getcsv($v, ",");}, file(self::IMAGE_PATH));
        $this->productSalesData = [];
        unset($csv[0]);
        foreach ($csv as $salesValue) {
            $this->productSalesData[$salesValue['0']][] = $salesValue;
        }
        return $csv;
    }

    public function processCsvData()
    {  
        $csv = $this->readCsvData();  
        $csvDataArray = [];
        $sku = array_column($csv, '0');
        $productData = $this->getProductsFromSku($sku);
        $imageAttributeId = $this->getAttributeFromCode('image');
        $smallImageAttributeId = $this->getAttributeFromCode('small_image');
        $thumbeAttributeId = $this->getAttributeFromCode('thumbnail');
        $mediaGalleryAttributeId = $this->getAttributeFromCode('media_gallery');

        $this->imageLabelAttributeId = $this->getAttributeFromCode('image_label');
        $this->smallLabelImageAttributeId = $this->getAttributeFromCode('small_image_label');
        $this->thumbeLabelAttributeId = $this->getAttributeFromCode('thumbnail_label');

        $rowIds = array_column($productData, 'row_id');
        $attributeIds = [$imageAttributeId, $smallImageAttributeId, $thumbeAttributeId];
        $data = $this->getProductsImage($rowIds, $attributeIds);
        $this->mediaGallerydata = $this->getMediaGalleryImage($rowIds, $mediaGalleryAttributeId);
        $attributeIdArray = [$imageAttributeId=>'image_label', $smallImageAttributeId=>'small_image_label', $thumbeAttributeId=>'thumbnail_label'];
        $this->attributeCodeArray = ['image_label'=>$this->imageLabelAttributeId, 'small_image_label'=>$this->smallLabelImageAttributeId, 'thumbnail_label'=>$this->thumbeLabelAttributeId];
        unset($this->productSalesData['SKU']);
        $i = 0;
        foreach($this->productSalesData as $key=>$value){
            if(isset($data[$key])){
                $imageText = $this->getImageLabelFromValue($data[$key], $value, $attributeIdArray, $mediaGalleryAttributeId);
                if($i == 3){
                    print_r($value);
                }
                $i++;
            }
        }
    }

    public function getImageLabelFromValue($imageData, $altTag, $attributeIdArray, $mediaGalleryAttributeId)
    {
        $imageDataArray = [];
        foreach($altTag as $key=>$value){
            $imageDataArray[] = $this->getImageText($value, $imageData, $attributeIdArray, $mediaGalleryAttributeId);
        }
        return $imageDataArray;
    }

    public function getImageText($imageValue, $imageData, $attributeIdArray, $mediaGalleryAttributeId)
    {
        $imageText = [];
        foreach($imageData as $key=>$value){
            if(isset($attributeIdArray[$key]) && basename($value['value']) == $imageValue[1]){
                $imageLableKey = $attributeIdArray[$key];
                //echo $this->attributeCodeArray[$imageLableKey]; exit;
                if(isset($this->attributeCodeArray[$imageLableKey])){
                    
                    $exits = $this->getImageFromTable($value['row_id'], $this->attributeCodeArray[$imageLableKey]);
                    if(empty($exits) != true){
                        $productTable = $this->connection->getTableName('catalog_product_entity_varchar');
                        $where = [$this->connection->quoteInto('value_id = ?', $exits['value_id'])];
                        $dataItemUpdate = ['value'=>$imageValue[2]];
                        $this->updateDataTable($productTable, $where, $dataItemUpdate);
                    } else {
                        $dataItem = ['store_id'=>0,'attribute_id'=>$this->attributeCodeArray[$imageLableKey],'row_id'=>$value['row_id'],'value'=>$imageValue[2]];
                        $productTable = $this->connection->getTableName('catalog_product_entity_varchar');
                        $this->insertTable($productTable, $dataItem);
                    }
                    
                }
            } 
        }
        if(isset($this->mediaGallerydata[$imageValue[0]])){
            foreach($this->mediaGallerydata[$imageValue[0]] as $keyG=>$valueG){
                if(basename($valueG['value']) == $imageValue[1]){
                    $productTable = $this->connection->getTableName('catalog_product_entity_media_gallery_value');
                    $where = [$this->connection->quoteInto('value_id = ?', $valueG['value_id'])];
                    $dataItemUpdate = ['label'=>$imageValue[2]];
                    $this->updateDataTable($productTable, $where, $dataItemUpdate);
                } 
            }
        }
        return $imageText;
    }

    public function getImageFromTable($rowId, $attributeId)
    {
        $product = $this->connection->getTableName('catalog_product_entity_varchar');
        $select = $this->connection->select()
            ->from($product, ['value_id'])
            ->where('row_id = ? ', $rowId)
            ->where('attribute_id = ? ', $attributeId)
            ->order('store_id DESC');
        $result = $this->connection->fetchAll($select);
        if(count($result) > 1 || count($result) == 1){
            return $result[0];
        }
        return $result;
    }

    public function updateDataTable($table, $where, $itemArray)
    {
        $this->connection->update($table, $itemArray ,$where);
    }

    public function insertTable($tableName, $itemArray)
    {
        $this->connection->insert($tableName, $itemArray);
    }
}

$DebugSourceSyncManual = $objectManager->get(UpdateImageAlt::class);;
$DebugSourceSyncManual->processCsvData();