<?php

declare(strict_types=1);

use Mailing\Controllers as C;
use Mailing\Core\Auth;
use Mailing\Core\Router;
use Mailing\Services\Cron;
use Mailing\Services\Updater;

$r = new Router();
$a = ['auth'];
$p = ['auth', 'project'];
$pe = ['auth', 'project', 'edit'];
$ad = ['auth', 'admin'];
$pub = ['public'];

// Public (no session): images, unsubscribe, view online, webhooks, API.
$r->get('/img/{token:[A-Za-z0-9]+}', [C\PublicController::class, 'image'], $pub);
$r->get('/u/{token}', [C\PublicController::class, 'unsubscribe'], $pub);
$r->post('/u/{token}', [C\PublicController::class, 'unsubscribePost'], $pub);
$r->get('/v/{token}', [C\PublicController::class, 'view'], $pub);
$r->post('/webhook/mailjet', [C\ApiController::class, 'mailjet'], $pub);
$r->get('/api/v1/ping', [C\ApiController::class, 'ping'], $pub);
$r->post('/api/v1/contacts', [C\ApiController::class, 'contacts'], $pub);
$r->post('/api/v1/contacts/unsubscribe', [C\ApiController::class, 'unsubscribe'], $pub);
$r->post('/api/v1/contacts/remove', [C\ApiController::class, 'remove'], $pub);
$r->post('/api/v1/events', [C\ApiController::class, 'event'], $pub);
$r->get('/f/{slug:[a-z0-9-]+}', [C\PublicFormController::class, 'show'], $pub);
$r->post('/f/{slug:[a-z0-9-]+}', [C\PublicFormController::class, 'submit'], $pub);
$r->get('/f/{slug:[a-z0-9-]+}/confirma/{token:[A-Za-z0-9]+}', [C\PublicFormController::class, 'confirm'], $pub);
$r->get('/cron/{key}', [C\ApiController::class, 'cron'], $pub);

// Session
$r->get('/login', [C\AuthController::class, 'form']);
$r->post('/login', [C\AuthController::class, 'login']);
$r->post('/logout', [C\AuthController::class, 'logout']);
$r->get('/perfil', [C\AuthController::class, 'profile'], $a);
$r->post('/perfil', [C\AuthController::class, 'saveProfile'], $a);
$r->post('/projecte', [C\DashboardController::class, 'switchProject'], $a);
$r->get('/avisos', [C\NotificationController::class, 'index'], $a);
$r->get('/avisos/{id:\d+}', [C\NotificationController::class, 'open'], $a);
$r->post('/avisos/llegits', [C\NotificationController::class, 'readAll'], $a);
$r->post('/perfil/avisos', [C\NotificationController::class, 'prefs'], $a);
$r->post('/push/subscripcio', [C\NotificationController::class, 'subscribe'], $a);
$r->post('/push/baixa', [C\NotificationController::class, 'unsubscribe'], $a);
$r->post('/push/prova', [C\NotificationController::class, 'test'], $a);

$r->get('/', [C\DashboardController::class, 'index'], $a);

// Campaigns
$r->get('/campanyes', [C\CampaignController::class, 'index'], $p);
$r->get('/campanyes/nova', [C\CampaignController::class, 'create'], $pe);
$r->post('/campanyes', [C\CampaignController::class, 'store'], $pe);
$r->get('/campanyes/{id:\d+}', [C\CampaignController::class, 'show'], $p);
$r->post('/campanyes/{id:\d+}', [C\CampaignController::class, 'update'], $pe);
$r->get('/campanyes/{id:\d+}/destinataris', [C\CampaignController::class, 'countAudience'], $p);
$r->post('/campanyes/{id:\d+}/prova', [C\CampaignController::class, 'test'], $pe);
$r->post('/campanyes/{id:\d+}/programa', [C\CampaignController::class, 'schedule'], $pe);
$r->post('/campanyes/{id:\d+}/desprograma', [C\CampaignController::class, 'unschedule'], $pe);
$r->post('/campanyes/{id:\d+}/pausa', [C\CampaignController::class, 'pause'], $pe);
$r->post('/campanyes/{id:\d+}/repren', [C\CampaignController::class, 'resume'], $pe);
$r->post('/campanyes/{id:\d+}/atura', [C\CampaignController::class, 'cancel'], $pe);
$r->post('/campanyes/{id:\d+}/duplica', [C\CampaignController::class, 'duplicate'], $pe);
$r->post('/campanyes/{id:\d+}/elimina', [C\CampaignController::class, 'destroy'], $pe);
$r->post('/campanyes/{id:\d+}/data', [C\CampaignController::class, 'move'], $pe);
$r->get('/campanyes/{id:\d+}/informe', [C\ReportController::class, 'show'], $p);
$r->get('/campanyes/{id:\d+}/destinataris.csv', [C\ReportController::class, 'export'], $p);
$r->get('/campanyes/{id:\d+}/previsualitza', [C\CampaignController::class, 'preview'], $p);

// Visual editor (campaigns and templates)
$r->get('/{kind:campanyes|plantilles}/{id:\d+}/editor', [C\EditorController::class, 'edit'], $pe);
$r->post('/{kind:campanyes|plantilles}/{id:\d+}/doc', [C\EditorController::class, 'save'], $pe);
$r->get('/{kind:campanyes|plantilles}/{id:\d+}/versions', [C\EditorController::class, 'versions'], $pe);
$r->get('/{kind:campanyes|plantilles}/{id:\d+}/versions/{v:\d+}', [C\EditorController::class, 'version'], $pe);
$r->post('/{kind:campanyes|plantilles}/{id:\d+}/versions/{v:\d+}/restaura', [C\EditorController::class, 'restore'], $pe);
$r->post('/{kind:campanyes|plantilles}/{id:\d+}/com-a-plantilla', [C\EditorController::class, 'asTemplate'], $pe);
$r->post('/editor/previsualitza', [C\EditorController::class, 'render'], $pe);
$r->post('/editor/imatges', [C\EditorController::class, 'upload'], $pe);
$r->post('/editor/ia/text', [C\EditorController::class, 'aiText'], $pe);
$r->post('/editor/ia/imatge', [C\EditorController::class, 'aiImage'], $pe);
$r->post('/editor/tradueix', [C\EditorController::class, 'translate'], $pe);

// Automations
$r->get('/automatitzacions', [C\AutomationController::class, 'index'], $p);
$r->post('/automatitzacions', [C\AutomationController::class, 'store'], $pe);
$r->get('/automatitzacions/{id:\d+}', [C\AutomationController::class, 'show'], $p);
$r->post('/automatitzacions/{id:\d+}', [C\AutomationController::class, 'update'], $pe);
$r->post('/automatitzacions/{id:\d+}/flux', [C\AutomationController::class, 'saveFlow'], $pe);
$r->post('/automatitzacions/{id:\d+}/activa', [C\AutomationController::class, 'activate'], $pe);
$r->post('/automatitzacions/{id:\d+}/pausa', [C\AutomationController::class, 'pause'], $pe);
$r->post('/automatitzacions/{id:\d+}/elimina', [C\AutomationController::class, 'destroy'], $pe);
$r->post('/automatitzacions/{id:\d+}/inscriu', [C\AutomationController::class, 'enroll'], $pe);
$r->post('/automatitzacions/{id:\d+}/prova', [C\AutomationController::class, 'simulate'], $p);
$r->post('/automatitzacions/{id:\d+}/inscripcions/{run:\d+}/treu', [C\AutomationController::class, 'removeRun'], $pe);

// Templates
$r->get('/plantilles', [C\TemplateController::class, 'index'], $p);
$r->post('/plantilles', [C\TemplateController::class, 'store'], $pe);
$r->post('/plantilles/importa', [C\TemplateController::class, 'import'], $pe);
$r->get('/plantilles/format', [C\TemplateController::class, 'format'], $p);
$r->get('/plantilles/{id:\d+}/exporta', [C\TemplateController::class, 'export'], $p);
$r->post('/plantilles/{id:\d+}/duplica', [C\TemplateController::class, 'duplicate'], $pe);
$r->post('/plantilles/{id:\d+}/elimina', [C\TemplateController::class, 'destroy'], $pe);
$r->get('/plantilles/{id:\d+}/previsualitza', [C\TemplateController::class, 'preview'], $p);

// Calendar
$r->get('/calendari', [C\CalendarController::class, 'index'], $p);

// Contacts, import, fields
$r->get('/contactes', [C\ContactController::class, 'index'], $p);
$r->get('/contactes/nou', [C\ContactController::class, 'create'], $pe);
$r->post('/contactes', [C\ContactController::class, 'store'], $pe);
$r->post('/contactes/accio', [C\ContactController::class, 'bulk'], $pe);
$r->get('/contactes/exporta', [C\ContactController::class, 'export'], $p);
$r->get('/contactes/{id:\d+}', [C\ContactController::class, 'show'], $p);
$r->post('/contactes/{id:\d+}', [C\ContactController::class, 'update'], $pe);
$r->post('/contactes/{id:\d+}/elimina', [C\ContactController::class, 'destroy'], $pe);
$r->get('/contactes/{id:\d+}/dades', [C\ContactController::class, 'gdpr'], $p);
$r->get('/contactes/importa', [C\ImportController::class, 'form'], $pe);
$r->post('/contactes/importa', [C\ImportController::class, 'upload'], $pe);
$r->post('/contactes/importa/executa', [C\ImportController::class, 'run'], $pe);
$r->get('/contactes/camps', [C\ContactController::class, 'fields'], $p);
$r->post('/contactes/camps', [C\ContactController::class, 'storeField'], $pe);
$r->post('/contactes/camps/{key:[a-z0-9_]+}/elimina', [C\ContactController::class, 'destroyField'], $pe);

// Lists and segments
$r->get('/llistes', [C\ListController::class, 'index'], $p);
$r->post('/llistes', [C\ListController::class, 'store'], $pe);
$r->post('/llistes/{id:\d+}', [C\ListController::class, 'update'], $pe);
$r->post('/llistes/{id:\d+}/elimina', [C\ListController::class, 'destroy'], $pe);
$r->get('/segments', [C\SegmentController::class, 'index'], $p);
$r->get('/segments/nou', [C\SegmentController::class, 'edit'], $pe);
$r->get('/segments/{id:\d+}', [C\SegmentController::class, 'edit'], $pe);
$r->post('/segments', [C\SegmentController::class, 'save'], $pe);
$r->post('/segments/{id:\d+}', [C\SegmentController::class, 'save'], $pe);
$r->post('/segments/compta', [C\SegmentController::class, 'count'], $p);
$r->post('/segments/{id:\d+}/elimina', [C\SegmentController::class, 'destroy'], $pe);

// Signup forms
$r->get('/formularis', [C\FormController::class, 'index'], $p);
$r->get('/formularis/nou', [C\FormController::class, 'edit'], $pe);
$r->get('/formularis/{id:\d+}', [C\FormController::class, 'edit'], $p);
$r->post('/formularis', [C\FormController::class, 'save'], $pe);
$r->post('/formularis/{id:\d+}', [C\FormController::class, 'save'], $pe);
$r->post('/formularis/{id:\d+}/elimina', [C\FormController::class, 'destroy'], $pe);

// Senders
$r->get('/remitents', [C\SenderController::class, 'index'], $p);
$r->post('/remitents', [C\SenderController::class, 'store'], $pe);
$r->post('/remitents/sincronitza', [C\SenderController::class, 'sync'], $pe);
$r->post('/remitents/{id:\d+}', [C\SenderController::class, 'update'], $pe);
$r->post('/remitents/{id:\d+}/defecte', [C\SenderController::class, 'makeDefault'], $pe);
$r->post('/remitents/{id:\d+}/elimina', [C\SenderController::class, 'destroy'], $pe);

// Sync connectors
$r->get('/sincronitzacio', [C\SyncController::class, 'index'], $p);
$r->get('/sincronitzacio/nou', [C\SyncController::class, 'edit'], $pe);
$r->get('/sincronitzacio/{id:\d+}', [C\SyncController::class, 'edit'], $pe);
$r->post('/sincronitzacio', [C\SyncController::class, 'save'], $pe);
$r->post('/sincronitzacio/{id:\d+}', [C\SyncController::class, 'save'], $pe);
$r->post('/sincronitzacio/{id:\d+}/executa', [C\SyncController::class, 'run'], $pe);
$r->post('/sincronitzacio/{id:\d+}/token', [C\SyncController::class, 'token'], $pe);
$r->post('/sincronitzacio/{id:\d+}/elimina', [C\SyncController::class, 'destroy'], $pe);

// Administration
$r->get('/projectes', [C\ProjectController::class, 'index'], $ad);
$r->get('/projectes/nou', [C\ProjectController::class, 'edit'], $ad);
$r->get('/projectes/{id:\d+}', [C\ProjectController::class, 'edit'], $ad);
$r->post('/projectes', [C\ProjectController::class, 'save'], $ad);
$r->post('/projectes/{id:\d+}', [C\ProjectController::class, 'save'], $ad);
$r->post('/projectes/{id:\d+}/arxiva', [C\ProjectController::class, 'archive'], $ad);
$r->get('/usuaris', [C\UserController::class, 'index'], $ad);
$r->get('/usuaris/nou', [C\UserController::class, 'edit'], $ad);
$r->get('/usuaris/{id:\d+}', [C\UserController::class, 'edit'], $ad);
$r->post('/usuaris', [C\UserController::class, 'save'], $ad);
$r->post('/usuaris/{id:\d+}', [C\UserController::class, 'save'], $ad);
$r->get('/configuracio', [C\SettingsController::class, 'index'], $ad);
$r->post('/configuracio', [C\SettingsController::class, 'save'], $ad);
$r->post('/configuracio/prova-mailjet', [C\SettingsController::class, 'testMailjet'], $ad);
$r->post('/configuracio/webhooks', [C\SettingsController::class, 'webhooks'], $ad);
$r->post('/configuracio/prova-ia', [C\SettingsController::class, 'testAi'], $ad);
$r->get('/actualitzacio', [C\UpdateController::class, 'index'], $ad);
$r->post('/actualitzacio/comprova', [C\UpdateController::class, 'check'], $ad);
$r->post('/actualitzacio/aplica', [C\UpdateController::class, 'apply'], $ad);
$r->post('/actualitzacio/migra', [C\UpdateController::class, 'migrate'], $ad);
$r->post('/actualitzacio/copia', [C\UpdateController::class, 'backup'], $ad);
$r->get('/actualitzacio/copia/{name:[A-Za-z0-9._-]+}', [C\UpdateController::class, 'download'], $ad);
$r->get('/activitat', [C\UpdateController::class, 'activity'], $ad);

// Maintenance mode (OTA in progress): only administrators get through.
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (Updater::inMaintenance() && !str_starts_with($path, '/img/') && !str_starts_with($path, '/login')) {
    Mailing\Core\Session::start();
    if (!Auth::isAdmin()) {
        http_response_code(503);
        header('Retry-After: 120');
        echo Mailing\Core\View::render('errors/error', ['code' => 503, 'message' => 'La plataforma s\'està actualitzant. Torna-ho a provar d\'aquí a un parell de minuts.'], 'layouts/bare');
        exit;
    }
}

// No cron? Signed-in pages move the queue along (after the response is sent).
if (!preg_match('#^/(cron|webhook|img|api|u|v|f)/#', $path)) {
    Cron::maybeInline();
}

$r->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
