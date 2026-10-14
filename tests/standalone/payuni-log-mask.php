<?php
// Standalone synthetic tests: no WordPress bootstrap, logger write or payment request.
define('ABSPATH', __DIR__);
function wc_print_r($data, $return) { return print_r($data, $return); }
require dirname(__DIR__, 2) . '/includes/payuni/src/apis/Payment.php';
class MaskTestLogger {
    public $message;
    public function log($level, $message, $context) { $this->message = $message; }
}
$sample = ['CardNo'=>'4111111111111111','CardCVC'=>'123','CardExpired'=>'1229','CreditHash'=>'synthetic-credit-secret','EncryptInfo'=>'synthetic-encrypted-secret','safe'=>'unchanged'];
$cases = [print_r($sample,true),json_encode($sample),json_encode(['nested'=>[$sample]]),'prefix '.json_encode($sample).' suffix',print_r(['nested'=>$sample],true),"Array\r\n(\r\n    [CardCVC] => 123\r\n    [safe] => unchanged\r\n)",json_encode(['CardNo'=>4111111111111111,'CardCVC'=>123,'CardExpired'=>null,'CreditHash'=>'abc\\"def','EncryptInfo'=>false,'safe'=>'unchanged']),json_encode(['CardNo'=>['sensitive'=>'4111111111111111'],'CardCVC'=>['123'],'safe'=>'unchanged'])];
$count=0;
function check_mask($input, $output) {
    global $count;
    foreach (['4111111111111111','123','1229','synthetic-credit-secret','synthetic-encrypted-secret','abc'] as $needle) {
        if (str_contains($output,$needle)) throw new Exception('Sensitive synthetic value was retained');
    }
    if (!str_contains($output,'unchanged')) throw new Exception('Non-sensitive content changed');
    if (!str_contains($output,'[已遮罩]')) throw new Exception('Mask missing');
    $count++;
}
foreach($cases as $case){
    PAYUNI\APIs\Payment::$log_enabled=true;
    PAYUNI\APIs\Payment::$log=new MaskTestLogger();
    PAYUNI\APIs\Payment::log($case);
    check_mask($case,PAYUNI\APIs\Payment::$log->message);
}
PAYUNI\APIs\Payment::log(['nested'=>$sample]);
check_mask('',PAYUNI\APIs\Payment::$log->message);
$before=PAYUNI\APIs\Payment::$log->message;PAYUNI\APIs\Payment::$log_enabled=false;PAYUNI\APIs\Payment::log($sample);if(PAYUNI\APIs\Payment::$log->message!==$before)throw new Exception('Disabled logger changed');$count++;
echo "PASS: $count synthetic assertions; no values printed\n";
