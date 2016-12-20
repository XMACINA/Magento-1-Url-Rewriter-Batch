<?php
/**
 * Created by PhpStorm.
 * User: Mohamed / xmacina.2001@gmail.com
 * Date: 19/12/2016
 * Time: 15:45
 */

require_once 'abstract.php';

class Xmacina_Shell_ReWriter extends Mage_Shell_Abstract
{

    const PAGE_SIZE = 1000;
    const REDIRECTION_PERMANENTE_301 = 'RP';
    const REDIRECTION_TEMPORAIRE_302 = 'R';
    const SUFFIX = '.html';
    const DESCRIP_URL = '[BATCH XMACINA URL RE-WRITER]';
    var $err_log_filename;
    var $tracer_log_filename;
    var $deleted_log_filename;
    var $csv_filename;
    
    // Batch Main
    public function run()
    {
        $rnd = Rand();
        $this->csv_filename = 'XMACINA_url_status_'.$rnd.'.csv';
        $this->tracer_log_filename = 'XMACINA_Url_ReWriter_'.$rnd.'.log';
        $this->err_log_filename =  'XMACINA_Url_ReWriter_KO_'.$rnd.'.log';
        $this->deleted_log_filename =  'XMACINA_Url_ReWriter_Deleted_RECORD_'.$rnd.'.log';
        ini_set('memory_limit', '-1');
        $startMsg = 'START TIME     >>>>>>>>> $  '.new Zend_Date() .PHP_EOL;
        $endMsg = 'END TIME       >>>>>>>>> $  '.new Zend_Date() .PHP_EOL;
        echo $startMsg;
        Mage::log(' # unlimited memory allowed # '.PHP_EOL, null, $this->tracer_log_filename, true);
        Mage::log($startMsg, null, $this->tracer_log_filename, true);
        if($this->getArg('store_id')) {
            echo 'StoreID OPTION -> ' . $this->getArg('store_id') . PHP_EOL;
            Mage::log('store_id OPTION -> ' . $this->getArg('store_id') . PHP_EOL, null, $this->tracer_log_filename, true);
            $this->bulkRedirectAction($this->getArg('store_id'));
        }elseif($this->getArg('delete_all')){
            $this->deleteAllAdded();
        }else{
            echo $this->usageHelp().PHP_EOL;
        }
        Mage::log($endMsg, null, $this->tracer_log_filename, true);
        echo $endMsg;
    }
    // Rewriting URL methode
    public function bulkRedirectAction($storeId){
        try {
            $productCollection = Mage::getModel('catalog/product')->getCollection()
                ->setStoreId($storeId);
            $productCollection->setPageSize(self::PAGE_SIZE);
            $pages = $productCollection->getLastPageNumber();
            $currentPage = 1;
            $count = 0;
            $csv = ';status;product id;url;target'.PHP_EOL;

            Mage::log($csv, null, $this->csv_filename, true);
            while($currentPage <= $pages) {

                $productCollection->setCurPage($currentPage);
                $productCollection->load();

                foreach($productCollection as $product){
                    $product->load();
                    $count++;
                    $idProduct = $product->getId();
                    $requestedURL = $product->getUrlKey().self::SUFFIX;
                    try{
                        $targetedURL = '';
                        $csv = ';'.$idProduct.';'.$requestedURL.';';
                        $message = 'Product id# '.$idProduct.' Identifier # '.$requestedURL;
                        if($requestedURL == self::SUFFIX){
                            $csv =';ERR'.$csv.$targetedURL.PHP_EOL;
                            Mage::log($csv, null, $this->csv_filename, true);
                            Mage::log($message, null, $this->err_log_filename, true);
                            continue;
                        }

                        $requestedURL = $this->getRequestedURL($storeId, $requestedURL);
                        if(!$this->isExistRequestURL($requestedURL,$storeId)){
                            $targetedURL = $this->getLongProductURL($product, $storeId);
                            if($targetedURL==$requestedURL || empty($targetedURL)){
                                $csv =';KO'.$csv.$targetedURL.PHP_EOL;
                                Mage::log($csv, null, $this->csv_filename, true);
                                echo $message = 'KO => '.$message.' # TargetURL '.$targetedURL.PHP_EOL;
                                Mage::log($message, null, $this->err_log_filename, true);
                                continue;
                            }
                            $csv =';OK'.$csv.$targetedURL.PHP_EOL;
                            $this->addUrlRedirection($requestedURL,$targetedURL,$storeId,self::REDIRECTION_PERMANENTE_301);
                            Mage::log($csv, null, $this->csv_filename, true);
                            $message = 'OK => '.$message;
                        }else{
                            $csv =';EXIST'.$csv.$targetedURL.PHP_EOL;
                            Mage::log($csv, null, $this->csv_filename, true);
                            $message = 'KO => '.$message.'  #ERR URL Redirect with same Request Path and Store already exists.'.PHP_EOL;
                            Mage::log($message, null, $this->err_log_filename, true);
                        }
                        $message.= PHP_EOL;
                        echo $message;
                        Mage::log($message, null, $this->tracer_log_filename, true);
                    } catch (Exception $e) {
                        Mage::log('Product id# '.$idProduct.' # ' . $e->getMessage(), null, $this->tracer_log_filename, true);
                    }
                }

                $currentPage++;
                $productCollection->clear();
            }
        } catch (Exception $e) {
            Mage::log($e->getMessage(), null, $this->tracer_log_filename, true);
        }
    }
    // get Target URL
    private function getTargetURL($categoryURL, $productURL){
        if(!empty($categoryURL) && !empty($productURL)){
            $url = str_replace(self::SUFFIX,'/'.$productURL.self::SUFFIX,$categoryURL);
            $url = str_replace('XMACINA/fr/','#',$url);
            $pos = strpos($url,'#');
            return substr($url,$pos+1);
        }
        return '';
    }
    // get product with categories
    private function getLongProductURL($product, $storeId){
        $productId = $product->getId();
        $arrayData = Mage::getModel('enterprise_urlrewrite/redirect')
            ->getCollection()
            ->addFieldToFilter('product_id', array('eq' => $productId))
            ->addFieldToFilter('store_id', array('eq' => $storeId))->getData();
        $res = array('p_url'=>'','nbr'=>0);
        if(empty($arrayData)){
            $categoryIds = $product->getCategoryIds();
            if(empty($categoryIds)){
                return '';
            }
            $categories = Mage::getModel('catalog/category')
                ->getCollection()
                ->addAttributeToFilter('entity_id', $categoryIds);
            foreach($categories as $item){
                $nbrChars = strlen($item->getCategoryUrl());
                if($res['nbr'] < $nbrChars && strpos($item->getCategoryUrl(),'catalog/category') === false){
                    $res['nbr'] = $nbrChars;
                    $res['p_url'] = $item->getCategoryUrl();
                }
            }
            $res['p_url'] = $this->getTargetURL($res['p_url'],$product->getUrlKey());
        }else{
            foreach($arrayData as $item){
                $nbrChars = strlen($item['identifier']);
                if($res['nbr'] < $nbrChars && strpos($item['identifier'],'catalog/category') === false){
                    $res['nbr'] = $nbrChars;
                    $res['p_url'] = $item['identifier'];
                }
            }
        }
        return $res['p_url'];
    }
   // check request if exist
    private function isExistRequestURL($requestedURL,$storeId){
        $urlCollection = Mage::getModel('enterprise_urlrewrite/redirect')->getCollection()
            ->addFieldToFilter('identifier', array('eq' => $requestedURL))
            ->addFieldToFilter('store_id', array('eq' => $storeId));
        if ($urlCollection->getSize()) {
           return true;
        }
    }
    // get request URL
    private function getRequestedURL($storeId, $requestedURL){
       $urlCollection = Mage::getModel('enterprise_urlrewrite/redirect')
            ->getCollection()
            ->addFieldToFilter('identifier', array('eq' => $requestedURL))
            ->addFieldToFilter('store_id', array('eq' => $storeId));
        foreach($urlCollection as $url){
            if(strpos($url->getTargetPath(),'html')){
               return $this->getRequestedURL($storeId,$url->getTargetPath());
            }
        }
        return $requestedURL;
    }
    
    // delete all record added by this Batch
    private function deleteAllAdded(){
        $rewCollection = Mage::getModel('enterprise_urlrewrite/redirect')
            ->getCollection()
            ->addFieldToFilter('description', array('eq' => self::DESCRIP_URL));
        foreach($rewCollection as $item){
            $ms = 'DELETED | '.$item->getRedirectId().' # enterprise_urlrewrite/redirect | '.$item->getIdentifier().PHP_EOL;
            echo $ms;
            Mage::log($ms, null, $this->deleted_log_filename, true);
            $item->delete();
        }
    }
    
    // add redirection to db
    private function addUrlRedirection($requestedURL,$targetedURL,$storeId, $redirectOption){
        $redModel = Mage::getModel('enterprise_urlrewrite/redirect');
        $data = array (
            'identifier'=>$requestedURL,
            'target_path'=>$targetedURL,
            'options'=>$redirectOption,
            'store_id'=>$storeId,
            'description'=>self::DESCRIP_URL);
        $redModel->addData($data);
        $redModel->save();
    }
    // help 
    public function usageHelp()
    {
        return "
        \n Usage:  php -f fix_attributes -- [options]
        \n    --store_id as (Int)   Store ID for create new redirection url product URL to Product categories URL
        \n    --delete_all   Delete All records added by This Batch
        \n    help                        This help
        ";
    }
}

$shell = new Xmacina_Shell_ReWriter();
$shell->run();
