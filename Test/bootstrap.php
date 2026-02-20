<?php
/**
 * Test bootstrap - stubs Magento framework classes for standalone unit testing.
 * Define Magento stubs BEFORE loading autoloader (which triggers registration.php).
 */

// --- Core Framework ---

namespace Magento\Framework\Component {
    if (!class_exists('Magento\Framework\Component\ComponentRegistrar')) {
        class ComponentRegistrar {
            const MODULE = 'module';
            public static function register($type, $name, $path) {}
        }
    }
}

namespace Magento\Framework\App {
    if (!interface_exists('Magento\Framework\App\ActionInterface')) {
        interface ActionInterface {
            public function execute();
        }
    }
    if (!interface_exists('Magento\Framework\App\RequestInterface')) {
        interface RequestInterface {
            public function getModuleName();
            public function setModuleName($name);
            public function getActionName();
            public function setActionName($name);
            public function getParam($key, $defaultValue = null);
            public function setParams(array $params);
            public function getParams();
            public function getCookie($name, $default = null);
            public function isSecure();
        }
    }
}

namespace Magento\Framework\App\Request {
    if (!class_exists('Magento\Framework\App\Request\InvalidRequestException')) {
        class InvalidRequestException extends \Exception {}
    }
}

namespace Magento\Framework\App {
    if (!interface_exists('Magento\Framework\App\CsrfAwareActionInterface')) {
        interface CsrfAwareActionInterface extends ActionInterface {
            public function createCsrfValidationException(RequestInterface $request): ?\Magento\Framework\App\Request\InvalidRequestException;
            public function validateForCsrf(RequestInterface $request): ?bool;
        }
    }
}

namespace Magento\Framework\App\Action {
    if (!interface_exists('Magento\Framework\App\Action\HttpPostActionInterface')) {
        interface HttpPostActionInterface extends \Magento\Framework\App\ActionInterface {}
    }
}

// --- Framework Utilities ---

namespace Magento\Framework\Controller\Result {
    if (!class_exists('Magento\Framework\Controller\Result\Json')) {
        class Json {
            private $data;
            private $httpResponseCode = 200;
            public function setData($data) { $this->data = $data; return $this; }
            public function getData() { return $this->data; }
            public function setHttpResponseCode($code) { $this->httpResponseCode = $code; return $this; }
            public function getHttpResponseCode() { return $this->httpResponseCode; }
        }
    }
    if (!class_exists('Magento\Framework\Controller\Result\JsonFactory')) {
        class JsonFactory {
            public function create() { return new Json(); }
        }
    }
}

namespace Magento\Framework\Serialize\Serializer {
    if (!class_exists('Magento\Framework\Serialize\Serializer\Json')) {
        class Json {
            public function serialize($data) { return json_encode($data); }
            public function unserialize($string) { return json_decode($string, true); }
        }
    }
}

namespace Magento\Framework\App\Config {
    if (!interface_exists('Magento\Framework\App\Config\ScopeConfigInterface')) {
        interface ScopeConfigInterface {
            public function getValue($path, $scopeType = 'default', $scopeCode = null);
            public function isSetFlag($path, $scopeType = 'default', $scopeCode = null);
        }
    }
}

namespace Magento\Store\Model {
    if (!class_exists('Magento\Store\Model\ScopeInterface')) {
        class ScopeInterface {
            const SCOPE_STORE = 'store';
        }
    }
}

namespace Magento\Framework\Encryption {
    if (!interface_exists('Magento\Framework\Encryption\EncryptorInterface')) {
        interface EncryptorInterface {
            public function encrypt($data);
            public function decrypt($data);
        }
    }
}

namespace Magento\Framework\DB {
    if (!class_exists('Magento\Framework\DB\Transaction')) {
        class Transaction {
            public function addObject($object) { return $this; }
            public function save() {}
        }
    }
}

namespace Magento\Framework\Exception {
    if (!class_exists('Magento\Framework\Exception\NoSuchEntityException')) {
        class NoSuchEntityException extends \RuntimeException {
            public function __construct($phrase = null, ?\Exception $cause = null, $code = 0) {
                $message = $phrase ? (string)$phrase : 'No such entity.';
                parent::__construct($message, (int)$code, $cause);
            }
        }
    }
}

namespace Magento\Framework\Api {
    if (!interface_exists('Magento\Framework\Api\SearchCriteriaInterface')) {
        interface SearchCriteriaInterface {}
    }
    if (!class_exists('Magento\Framework\Api\SearchCriteria')) {
        class SearchCriteria implements SearchCriteriaInterface {}
    }
    if (!class_exists('Magento\Framework\Api\SearchCriteriaBuilder')) {
        class SearchCriteriaBuilder {
            public function addFilter($field, $value, $conditionType = 'eq') { return $this; }
            public function create() { return new SearchCriteria(); }
        }
    }
}

// --- Sales Module ---

namespace Magento\Sales\Api\Data {
    if (!interface_exists('Magento\Sales\Api\Data\OrderInterface')) {
        interface OrderInterface {}
    }
    if (!interface_exists('Magento\Sales\Api\Data\OrderPaymentInterface')) {
        interface OrderPaymentInterface {}
    }
    if (!interface_exists('Magento\Sales\Api\Data\OrderPaymentSearchResultInterface')) {
        interface OrderPaymentSearchResultInterface {
            public function getItems();
            public function getTotalCount();
        }
    }
}

namespace Magento\Sales\Api {
    if (!interface_exists('Magento\Sales\Api\OrderRepositoryInterface')) {
        interface OrderRepositoryInterface {
            public function get($id);
            public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria);
            public function save(\Magento\Sales\Api\Data\OrderInterface $entity);
            public function delete(\Magento\Sales\Api\Data\OrderInterface $entity);
        }
    }
    if (!interface_exists('Magento\Sales\Api\OrderManagementInterface')) {
        interface OrderManagementInterface {
            public function cancel($id);
        }
    }
    if (!interface_exists('Magento\Sales\Api\OrderPaymentRepositoryInterface')) {
        interface OrderPaymentRepositoryInterface {
            public function get($id);
            public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria);
            public function save(\Magento\Sales\Api\Data\OrderPaymentInterface $entity);
            public function delete(\Magento\Sales\Api\Data\OrderPaymentInterface $entity);
        }
    }
}

namespace Magento\Sales\Model {
    if (!class_exists('Magento\Sales\Model\Order')) {
        class Order implements \Magento\Sales\Api\Data\OrderInterface {
            const STATE_NEW = 'new';
            const STATE_PENDING_PAYMENT = 'pending_payment';
            const STATE_PROCESSING = 'processing';
            const STATE_CANCELED = 'canceled';
            const STATE_COMPLETE = 'complete';
            const STATE_CLOSED = 'closed';
            public function getId() {}
            public function getState() {}
            public function getStatus() {}
            public function setState($state) {}
            public function setStatus($status) {}
            public function getPayment() {}
            public function canInvoice() {}
            public function canCancel() {}
            public function hasInvoices() {}
            public function getInvoiceCollection() {}
            public function addCommentToStatusHistory($comment) {}
            public function getCustomerId() {}
            public function getQuoteId() {}
            public function getIncrementId() {}
            public function getGrandTotal() {}
            public function getOrderCurrencyCode() {}
            public function setIsInProcess($flag) {}
        }
    }
}

namespace Magento\Sales\Model\Order {
    if (!class_exists('Magento\Sales\Model\Order\Payment')) {
        class Payment implements \Magento\Sales\Api\Data\OrderPaymentInterface {
            public function getAdditionalInformation($key = null) {}
            public function setAdditionalInformation($key, $value = null) {}
            public function getParentId() {}
            public function getMethod() {}
        }
    }
    if (!class_exists('Magento\Sales\Model\Order\Invoice')) {
        class Invoice {
            const CAPTURE_OFFLINE = 'offline';
            public function setRequestedCaptureCase($case) {}
            public function register() {}
            public function getOrder() {}
            public function getIncrementId() {}
            public function getId() {}
        }
    }
    if (!class_exists('Magento\Sales\Model\Order\Creditmemo')) {
        class Creditmemo {
            public function setInvoice($invoice) {}
        }
    }
    if (!class_exists('Magento\Sales\Model\Order\CreditmemoFactory')) {
        class CreditmemoFactory {
            public function createByOrder($order) {}
        }
    }
}

namespace Magento\Sales\Model\Order\Status {
    if (!class_exists('Magento\Sales\Model\Order\Status\History')) {
        class History {
            public function setIsCustomerNotified($flag) { return $this; }
            public function save() { return $this; }
        }
    }
}

namespace Magento\Sales\Model\Order\Email\Sender {
    if (!class_exists('Magento\Sales\Model\Order\Email\Sender\InvoiceSender')) {
        class InvoiceSender {
            public function send($invoice) {}
        }
    }
}

namespace Magento\Sales\Model\Service {
    if (!class_exists('Magento\Sales\Model\Service\InvoiceService')) {
        class InvoiceService {
            public function prepareInvoice($order) {}
        }
    }
    if (!class_exists('Magento\Sales\Model\Service\CreditmemoService')) {
        class CreditmemoService {
            public function refund($creditmemo) {}
        }
    }
}

namespace Magento\Sales\Model\ResourceModel\Order\Invoice {
    if (!class_exists('Magento\Sales\Model\ResourceModel\Order\Invoice\Collection')) {
        class Collection {
            public function getFirstItem() {}
        }
    }
}

// --- Global namespace ---

namespace {
    if (!function_exists('__')) {
        function __() {
            $args = func_get_args();
            $text = array_shift($args);
            if (empty($args)) {
                return $text;
            }
            // Magento uses %1, %2 style placeholders
            foreach ($args as $i => $arg) {
                $text = str_replace('%' . ($i + 1), (string)$arg, $text);
            }
            return $text;
        }
    }

    // Load composer autoloader (after stubs are defined)
    require __DIR__ . '/../vendor/autoload.php';
}
