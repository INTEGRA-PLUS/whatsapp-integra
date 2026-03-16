<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\Api\MessageApiController;
use Illuminate\Http\Request;
use App\Models\Instance;
use App\Services\MetaWhatsAppService;

// Create a dummy request
$request = Request::create('/api/v1/whatsapp-messages', 'GET', [
    'incoming_company_nit' => '900123456',
    'date_from' => '2026-03-01',
    'date_to' => '2026-03-16',
    'per_page' => 5
]);

// If there's an instance in DB, we use its token, otherwise we just test the validation
$instance = Instance::first();
if ($instance) {
    $request->headers->set('X-Instance-Token', $instance->phone_number_id);
}

$controller = app()->make(MessageApiController::class);
$response = $controller->getWhatsAppMessages($request);

echo "Token present: " . ($instance ? 'Yes' : 'No') . "\n";
echo "Response Status: " . $response->getStatusCode() . "\n";
echo "Response Content: \n" . $response->getContent() . "\n";
