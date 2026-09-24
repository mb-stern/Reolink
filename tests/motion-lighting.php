<?php
// Run with PHP 8.2+: php tests/motion-lighting.php. No camera or Symcon needed.
const VM_UPDATE = 10603;
const IPS_KERNELMESSAGE = 10100;
const KR_READY = 10103;
$variables = [];
$actions = [];
$failAction = false;
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['variables'][$id]); }
function IPS_GetVariable(int $id): array { return $GLOBALS['variables'][$id]; }
function GetValue(int $id): mixed { return $GLOBALS['variables'][$id]['value']; }
function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_SemaphoreEnter(string $name, int $timeout): bool { return true; }
function IPS_SemaphoreLeave(string $name): void {}
function RequestAction(int $id, mixed $value): bool {
    if ($GLOBALS['failAction']) return false;
    $GLOBALS['actions'][] = [$id, $value];
    $GLOBALS['variables'][$id]['value'] = $value;
    return true;
}
class IPSModuleStrict {
    public int $InstanceID = 100;
    public array $props = ['InstanceStatus' => true, 'ShowSnapshots' => false];
    public array $attrs = [], $timers = [], $messages = [], $references = [], $debug = [];
    public array $idents = ['Person' => 1, 'Tier' => 2, 'Fahrzeug' => 3];
    public function RegisterPropertyBoolean($n, $v) { $this->props[$n] = $v; }
    public function RegisterPropertyInteger($n, $v) { $this->props[$n] = $v; }
    public function RegisterPropertyFloat($n, $v) { $this->props[$n] = $v; }
    public function RegisterAttributeInteger($n, $v) { $this->attrs[$n] ??= $v; }
    public function RegisterAttributeString($n, $v) { $this->attrs[$n] ??= $v; }
    public function ReadPropertyBoolean($n) { return $this->props[$n] ?? false; }
    public function ReadPropertyInteger($n) { return $this->props[$n] ?? 0; }
    public function ReadPropertyFloat($n) { return $this->props[$n] ?? 0.0; }
    public function ReadAttributeInteger($n) { return $this->attrs[$n]; }
    public function ReadAttributeString($n) { return $this->attrs[$n]; }
    public function WriteAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    public function WriteAttributeString($n, $v) { $this->attrs[$n] = $v; }
    public function RegisterTimer($n, $v, $script) { $this->timers[$n] = $v; }
    public function SetTimerInterval($n, $v) { $this->timers[$n] = $v; }
    public function RegisterMessage($id, $msg) { $this->messages[$id . ':' . $msg] = true; }
    public function UnregisterMessage($id, $msg) { unset($this->messages[$id . ':' . $msg]); }
    public function RegisterReference($id) { $this->references[$id] = true; }
    public function UnregisterReference($id) { unset($this->references[$id]); }
    public function GetIDForIdent($ident) { return $this->idents[$ident] ?? false; }
    public function SetValue($ident, $value) { $GLOBALS['variables'][$this->idents[$ident]]['value'] = $value; }
    public function SendDebug($topic, $message, $format) { $this->debug[] = $message; }
    public function RegisterVariableBoolean($ident, $name, $profile, $position) {
        $this->idents[$ident] = 8;
        $GLOBALS['variables'][8] ??= ['VariableType' => 0, 'VariableAction' => 0, 'value' => false];
    }
    public function DisableAction($ident) { $GLOBALS['variables'][$this->idents[$ident]]['VariableAction'] = 0; }
}
require __DIR__ . '/../REOCAM/module.php';
function invoke(Reolink $m, string $name, ...$args): mixed {
    return (new ReflectionMethod($m, $name))->invoke($m, ...$args);
}
function fixture(): Reolink {
    $GLOBALS['actions'] = [];
    $GLOBALS['failAction'] = false;
    $GLOBALS['variables'] = [];
    foreach ([1, 2, 3, 4, 5, 6, 7] as $id) {
        $GLOBALS['variables'][$id] = ['VariableType' => 0, 'VariableAction' => $id === 4 || $id === 7 ? 99 : 0, 'VariableCustomAction' => 0, 'value' => false];
    }
    $GLOBALS['variables'][5] = ['VariableType' => 2, 'value' => 10.0];
    $m = new Reolink();
    invoke($m, 'CreateMotionLighting');
    $m->props = array_merge($m->props, ['MotionLightEnabled' => true, 'MotionLightUsePerson' => true,
        'MotionLightUseAnimal' => true, 'MotionLightUseVehicle' => true, 'MotionLightTarget' => 4, 'MotionLightBrightness' => 5]);
    invoke($m, 'ApplyMotionLighting');
    return $m;
}
$count = 0;
function check(bool $result, string $name): void {
    if (!$result) throw new RuntimeException('FAIL: ' . $name);
    $GLOBALS['count']++;
    echo 'PASS: ' . $name . PHP_EOL;
}

$m = fixture();
invoke($m, 'ProcessAllData', ['alarm' => ['type' => 'PEOPLE']]);
check($actions === [[4, true]], 'Webhook schaltet Ziel ein');
check($m->timers['Person_Reset'] === 5000, 'Bestehender 5-Sekunden-Timer bleibt bestehen');
$m->ResetMoveTimer('Person');
check(GetValue(1) === false && GetValue(4) === true, 'Alter Reset schaltet das Licht nicht aus');
$m->attrs['MotionLightLastDetection'] = time() - 100;
$lastDarkDetection = $m->attrs['MotionLightLastDetection'];
$variables[5]['value'] = 1000.0;
invoke($m, 'ProcessAllData', ['alarm' => ['type' => 'ANIMAL']]);
check($m->attrs['MotionLightLastDetection'] === $lastDarkDetection && count($actions) === 1, 'Tier verlängert oberhalb Schwellwert nicht');
$m->MotionLightTimer();
check(GetValue(4) === true, 'Früher Timer-Aufruf schaltet nicht aus');
$m->attrs['MotionLightLastDetection'] = time() - 120;
$m->MotionLightTimer();
check($actions === [[4, true], [4, false]] && $m->timers['MotionLightTimer'] === 0, 'Nachlaufzeit schaltet aus und stoppt Zusatz-Timer');
invoke($m, 'MotionLightingCameraDetection', 'Person');
check(count($actions) === 2, 'Weitere Erkennung bei Helligkeit schaltet nicht wieder ein');
$variables[5]['value'] = 29.0;
invoke($m, 'MotionLightingCameraDetection', 'Person');
check($actions === [[4, true], [4, false], [4, true]], 'Neue dunkle Erkennung darf erneut einschalten');
$m->attrs['MotionLightLastDetection'] = time() - 50;
$lastDarkDetection = $m->attrs['MotionLightLastDetection'];
$variables[5]['value'] = 30.0;
invoke($m, 'MotionLightingCameraDetection', 'Fahrzeug');
check($m->attrs['MotionLightLastDetection'] === $lastDarkDetection, 'Auch exakt am Schwellwert keine Verlängerung');
$variables[5]['value'] = 29.0;
invoke($m, 'MotionLightingCameraDetection', 'Fahrzeug');
check($m->attrs['MotionLightLastDetection'] >= time() - 1, 'Unter Schwellwert wieder Verlängerung möglich');

$m = fixture();
$variables[5]['value'] = 30.0;
invoke($m, 'MotionLightingCameraDetection', 'Fahrzeug');
check(!$actions, 'Am Schwellwert wird nicht eingeschaltet');
$variables[5]['value'] = 29.9;
invoke($m, 'MotionLightingCameraDetection', 'Fahrzeug');
check($actions === [[4, true]], 'Unterhalb des Schwellwerts schaltet Fahrzeug ein');

$m = fixture();
$variables[1]['value'] = true;
invoke($m, 'PollingUpdateState', 'people', 1);
$m->attrs['MotionLightLastDetection'] = time() - 100;
invoke($m, 'PollingUpdateState', 'people', 1);
check($m->attrs['MotionLightLastDetection'] >= time() - 1 && $actions === [[4, true]], 'Unverändert positives Polling verlängert');
$before = $m->attrs['MotionLightLastDetection'];
invoke($m, 'PollingUpdateState', 'people', 0);
check($m->attrs['MotionLightLastDetection'] === $before, 'Negatives Polling verlängert nicht');

// All eight combinations: any enabled category can trigger independently.
for ($mask = 0; $mask < 8; $mask++) {
    foreach (['Person', 'Tier', 'Fahrzeug'] as $bit => $ident) {
        $m = fixture();
        foreach (['Person', 'Animal', 'Vehicle'] as $i => $name) {
            $m->props['MotionLightUse' . $name] = (bool)($mask & (1 << $i));
        }
        invoke($m, 'MotionLightingCameraDetection', $ident);
        check((count($actions) === 1) === (bool)($mask & (1 << $bit)), 'Kombination ' . $mask . ': ' . $ident);
    }
}
$m = fixture();
$m->props['MotionLightUseAnimal'] = false;
invoke($m, 'ApplyMotionLighting');
check(invoke($m, 'MotionLightingSources') === [1, 3], 'Mensch und Fahrzeug automatisch gefunden, Tier aus');
$m->MessageSink(1, 6, VM_UPDATE, [true, false]);
check(!$actions, 'Fremde Variablen werden ignoriert');

$m = fixture();
$m->props['MotionLightEnabled'] = false;
invoke($m, 'ProcessAllData', ['alarm' => ['type' => 'PEOPLE']]);
check(!$actions && $m->timers['Person_Reset'] === 5000, 'Deaktivierte Erweiterung lässt Kamera-Logik unverändert');
$m = fixture();
$variables[4]['value'] = true;
invoke($m, 'MotionLightingCameraDetection', 'Person');
$m->MotionLightTimer();
check(!$actions && GetValue(4) === true, 'Bereits manuell eingeschaltetes Licht wird nicht übernommen');

$m = fixture();
invoke($m, 'MotionLightingCameraDetection', 'Person');
$m->props['MotionLightEnabled'] = false;
invoke($m, 'ApplyMotionLighting');
check($actions === [[4, true], [4, false]], 'Deaktivierung beendet eigene Lichtsteuerung');
$m = fixture();
invoke($m, 'MotionLightingCameraDetection', 'Person');
$m->props['MotionLightTarget'] = 7;
invoke($m, 'ApplyMotionLighting');
invoke($m, 'MotionLightingCameraDetection', 'Person');
check($actions === [[4, true], [4, false], [7, true]], 'Zielwechsel schaltet altes Ziel aus');

$m = fixture();
$variables[4]['VariableAction'] = 0;
invoke($m, 'MotionLightingCameraDetection', 'Person');
check(!$actions && invoke($m, 'MotionLightingError') !== null, 'Ziel ohne Aktion wird abgelehnt');
$m = fixture();
unset($variables[5]);
invoke($m, 'MotionLightingCameraDetection', 'Person');
check(!$actions, 'Fehlender Helligkeitssensor verhindert Einschalten');
$m = fixture();
$m->props['MotionLightTarget'] = 1;
$variables[1]['VariableAction'] = 99;
check(invoke($m, 'MotionLightingError') !== null, 'Rückkopplung zwischen Quelle und Ziel abgelehnt');

$m = fixture();
$failAction = true;
invoke($m, 'MotionLightingCameraDetection', 'Person');
check($m->attrs['MotionLightOwnedTarget'] === 0, 'Fehlgeschlagenes Einschalten übernimmt kein Ziel');
$failAction = false;
invoke($m, 'MotionLightingCameraDetection', 'Person');
$m->attrs['MotionLightLastDetection'] = time() - 120;
$failAction = true;
$m->MotionLightTimer();
check($m->attrs['MotionLightOwnedTarget'] === 4 && $m->timers['MotionLightTimer'] === 5000, 'Fehlgeschlagenes Ausschalten bleibt für Wiederholung vorgemerkt');
$failAction = false;
$m->MotionLightTimer();
check($m->attrs['MotionLightOwnedTarget'] === 0 && GetValue(4) === false, 'Wiederholung schaltet erfolgreich aus');

$m = fixture();
invoke($m, 'MotionLightingCameraDetection', 'Person');
$m->timers['MotionLightTimer'] = 0;
$m->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]);
check($m->timers['MotionLightTimer'] === 1000 && count($actions) === 1, 'Neustart stellt laufenden Timer wieder her');
$m->attrs['MotionLightLastDetection'] = time() - 121;
$m->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]);
check(GetValue(4) === false, 'Nach Neustart abgelaufene Nachlaufzeit wird abgearbeitet');
$m = fixture();
invoke($m, 'MotionLightingCameraDetection', 'Person');
$m->attrs['MotionLightLastDetection'] = time() - 10;
$m->props['MotionLightDelay'] = 5;
invoke($m, 'ApplyMotionLighting');
check(GetValue(4) === false, 'Geänderte Nachlaufzeit bezieht sich auf letzte Erkennung');

foreach ([false, true] as $enabled) {
    for ($mask = 0; $mask < 8; $mask++) {
        $m = fixture();
        $m->props['MotionLightEnabled'] = $enabled;
        foreach (['Person', 'Animal', 'Vehicle'] as $i => $name) {
            $m->props['MotionLightUse' . $name] = (bool)($mask & (1 << $i));
        }
        invoke($m, 'ApplyMotionLighting');
        check(GetValue(8) === ($enabled && $mask !== 0), 'Status Hauptschalter ' . (int)$enabled . ', Kombination ' . $mask);
        check(IPS_GetVariable(8)['VariableAction'] === 0, 'Status ohne Schaltaktion');
    }
}
echo $count . ' checks passed.' . PHP_EOL;
