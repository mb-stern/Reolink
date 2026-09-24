<?php

/** Additional light automation; the camera's existing reset timers are untouched. */
trait ReolinkMotionLighting
{
    private function CreateMotionLighting(): void
    {
        $this->RegisterPropertyBoolean('MotionLightEnabled', false);
        foreach (['Target', 'Brightness'] as $name) {
            $this->RegisterPropertyInteger('MotionLight' . $name, 0);
        }
        foreach (['Person', 'Animal', 'Vehicle'] as $name) {
            $this->RegisterPropertyBoolean('MotionLightUse' . $name, false);
        }
        $this->RegisterPropertyFloat('MotionLightThreshold', 30.0);
        $this->RegisterPropertyInteger('MotionLightDelay', 120);
        $this->RegisterAttributeInteger('MotionLightOwnedTarget', 0);
        $this->RegisterAttributeInteger('MotionLightLastDetection', 0);
        $this->RegisterAttributeString('MotionLightReferences', '[]');
        $this->RegisterTimer('MotionLightTimer', 0, 'REOCAM_MotionLightTimer($_IPS[\'TARGET\']);');
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    private function MotionLightingForm(): array
    {
        return [
            'type' => 'ExpansionPanel', 'caption' => 'Bewegungsmelder / Lichtsteuerung',
            'items' => [
                ['type' => 'CheckBox', 'name' => 'MotionLightEnabled', 'caption' => 'Zusätzlichen Bewegungsmelder aktivieren'],
                ['type' => 'CheckBox', 'name' => 'MotionLightUsePerson', 'caption' => 'Mensch als Auslöser verwenden'],
                ['type' => 'CheckBox', 'name' => 'MotionLightUseAnimal', 'caption' => 'Tier als Auslöser verwenden'],
                ['type' => 'CheckBox', 'name' => 'MotionLightUseVehicle', 'caption' => 'Fahrzeug als Auslöser verwenden'],
                ['type' => 'SelectVariable', 'name' => 'MotionLightTarget', 'caption' => 'Schaltvariable (Boolean mit Aktion)'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'SelectVariable', 'name' => 'MotionLightBrightness', 'caption' => 'Helligkeitsvariable (lux)'],
                    ['type' => 'NumberSpinner', 'name' => 'MotionLightThreshold', 'caption' => 'Einschalten unter Schwellwert', 'digits' => 0, 'suffix' => ' lux'],
                ]],
                ['type' => 'NumberSpinner', 'name' => 'MotionLightDelay', 'caption' => 'Nachlaufzeit ab letzter Erkennung', 'suffix' => ' Sekunden', 'minimum' => 1, 'maximum' => 86400],
                ['type' => 'Label', 'caption' => 'Erkennungsarten beliebig kombinieren. Die eigenen Kamera-Variablen werden automatisch verwendet. Helligkeitsvariable und Schwellwert müssen Werte in lux verwenden.'],
                ['type' => 'Label', 'caption' => 'Jede erneute Erkennung verlängert die Nachlaufzeit. Die bestehenden 5-Sekunden-Timer bleiben unverändert.'],
                ['type' => 'Label', 'caption' => 'Bereits eingeschaltete Ziele werden nicht übernommen. Beim Deaktivieren wird ein durch diese Automatik eingeschaltetes Ziel ausgeschaltet.'],
                ['type' => 'Label', 'caption' => 'Konfiguration: ' . ($this->MotionLightingError() ?? 'gültig')],
            ],
        ];
    }

    private function MotionLightingSources(): array
    {
        $sources = [];
        foreach (['Person' => 'Person', 'Animal' => 'Tier', 'Vehicle' => 'Fahrzeug'] as $name => $ident) {
            if (!$this->ReadPropertyBoolean('MotionLightUse' . $name)) continue;
            $id = @$this->GetIDForIdent($ident);
            if ($id !== false && $id > 0) $sources[] = $id;
        }
        return $sources;
    }

    private function MotionLightingError(): ?string
    {
        if (!$this->ReadPropertyBoolean('MotionLightEnabled')) return null;
        $sources = $this->MotionLightingSources();
        if (!$sources) return 'Mindestens eine Erkennungsart einschalten und Bewegungsvariablen der Kamera aktivieren.';
        foreach ($sources as $id) {
            if (!IPS_VariableExists($id) || IPS_GetVariable($id)['VariableType'] !== 0) {
                return 'Erkennungsvariablen müssen vorhandene Boolean-Variablen sein.';
            }
        }
        $target = $this->ReadPropertyInteger('MotionLightTarget');
        if (!IPS_VariableExists($target) || IPS_GetVariable($target)['VariableType'] !== 0) {
            return 'Eine Boolean-Schaltvariable auswählen.';
        }
        $v = IPS_GetVariable($target);
        if (($v['VariableCustomAction'] ?? 0) <= 0 && ($v['VariableAction'] ?? 0) <= 0) {
            return 'Die Schaltvariable benötigt eine Standardaktion oder ein Aktionsskript.';
        }
        $brightness = $this->ReadPropertyInteger('MotionLightBrightness');
        if (!IPS_VariableExists($brightness) || !in_array(IPS_GetVariable($brightness)['VariableType'], [1, 2], true)) {
            return 'Eine numerische Helligkeitsvariable auswählen.';
        }
        if (in_array($target, $sources, true)) return 'Schaltvariable und Erkennungsvariable müssen verschieden sein.';
        $delay = $this->ReadPropertyInteger('MotionLightDelay');
        if ($delay < 1 || $delay > 86400) return 'Nachlaufzeit muss zwischen 1 und 86400 Sekunden liegen.';
        if (!is_finite($this->ReadPropertyFloat('MotionLightThreshold'))) return 'Ungültiger Helligkeitsschwellwert.';
        return null;
    }

    private function ApplyMotionLighting(): void
    {
        // Read-only configuration status: not motion, light state or readiness.
        $this->RegisterVariableBoolean('MotionLightActive', 'Bewegungsmelder aktiv', '~Switch', 19);
        $this->DisableAction('MotionLightActive');
        $this->SetValue('MotionLightActive', $this->ReadPropertyBoolean('MotionLightEnabled') && (
            $this->ReadPropertyBoolean('MotionLightUsePerson')
            || $this->ReadPropertyBoolean('MotionLightUseAnimal')
            || $this->ReadPropertyBoolean('MotionLightUseVehicle')
        ));
        foreach (json_decode($this->ReadAttributeString('MotionLightReferences'), true) ?: [] as $id) {
            $this->UnregisterMessage($id, VM_UPDATE);
            $this->UnregisterReference($id);
        }
        $refs = array_values(array_unique(array_filter(array_merge($this->MotionLightingSources(), [
            $this->ReadPropertyInteger('MotionLightTarget'),
            $this->ReadPropertyInteger('MotionLightBrightness'),
        ]), static fn(int $id): bool => $id > 0 && IPS_VariableExists($id))));
        foreach ($refs as $id) $this->RegisterReference($id);
        $this->WriteAttributeString('MotionLightReferences', json_encode($refs));
        if ($this->ReadPropertyBoolean('InstanceStatus') && $this->ReadPropertyBoolean('MotionLightEnabled')) {
            foreach ($this->MotionLightingSources() as $id) {
                if (IPS_VariableExists($id)) $this->RegisterMessage($id, VM_UPDATE);
            }
            $error = $this->MotionLightingError();
            if ($error !== null) $this->SendDebug('Bewegungsmelder', $error, 0);
        }
        // Attributes survive a kernel restart; do not lose an outstanding switch-off.
        $this->SetTimerInterval('MotionLightTimer', $this->ReadAttributeInteger('MotionLightOwnedTarget') > 0 ? 1000 : 0);
        if (IPS_GetKernelRunlevel() === KR_READY) $this->MotionLightTimer();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELMESSAGE && ($Data[0] ?? null) === KR_READY) {
            $this->ApplyMotionLighting();
            return;
        }
        if ($Message !== VM_UPDATE || ($Data[0] ?? null) !== true) return;
        // Own camera detections arrive directly, including unchanged polling values.
        foreach (['Person', 'Tier', 'Fahrzeug'] as $ident) {
            if ($SenderID === @$this->GetIDForIdent($ident)) return;
        }
        $this->MotionLightingDetection($SenderID);
    }

    private function MotionLightingCameraDetection(string $ident): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id) $this->MotionLightingDetection($id);
    }

    private function MotionLightingDetection(int $source): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY || !$this->ReadPropertyBoolean('InstanceStatus')
            || !$this->ReadPropertyBoolean('MotionLightEnabled')
            || !in_array($source, $this->MotionLightingSources(), true)
            || $this->MotionLightingError() !== null) return;
        $lock = 'ReolinkMotionLight_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) return;
        try {
            $target = $this->ReadPropertyInteger('MotionLightTarget');
            $owned = $this->ReadAttributeInteger('MotionLightOwnedTarget');
            // Finish an old target before acquiring a newly configured target.
            if ($owned !== 0 && $owned !== $target && !$this->MotionLightingRelease()) return;
            // Brightness gates both starting and extending the follow-up time.
            $brightness = (float)GetValue($this->ReadPropertyInteger('MotionLightBrightness'));
            if (!is_finite($brightness) || $brightness >= $this->ReadPropertyFloat('MotionLightThreshold')) return;
            if ($this->ReadAttributeInteger('MotionLightOwnedTarget') === $target) {
                $this->WriteAttributeInteger('MotionLightLastDetection', time());
                $this->SetTimerInterval('MotionLightTimer', 1000);
                return;
            }
            if (GetValue($target) === true) return; // Do not switch off a pre-existing manual light.
            if (!$this->MotionLightingSwitch($target, true)) return;
            $this->WriteAttributeInteger('MotionLightOwnedTarget', $target);
            $this->WriteAttributeInteger('MotionLightLastDetection', time());
            $this->SetTimerInterval('MotionLightTimer', 1000);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    public function MotionLightTimer(): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) return;
        $lock = 'ReolinkMotionLight_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) return;
        try {
            $owned = $this->ReadAttributeInteger('MotionLightOwnedTarget');
            if ($owned === 0) {
                $this->SetTimerInterval('MotionLightTimer', 0);
                return;
            }
            $stop = !$this->ReadPropertyBoolean('InstanceStatus') || !$this->ReadPropertyBoolean('MotionLightEnabled')
                || $owned !== $this->ReadPropertyInteger('MotionLightTarget') || $this->MotionLightingError() !== null;
            $deadline = $this->ReadAttributeInteger('MotionLightLastDetection') + $this->ReadPropertyInteger('MotionLightDelay');
            if ($stop || time() >= $deadline) $this->MotionLightingRelease();
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function MotionLightingRelease(): bool
    {
        $id = $this->ReadAttributeInteger('MotionLightOwnedTarget');
        if ($id > 0 && IPS_VariableExists($id) && !$this->MotionLightingSwitch($id, false)) {
            // Keep ownership and retry. An action error must not orphan a light.
            $this->SetTimerInterval('MotionLightTimer', 5000);
            return false;
        }
        $this->WriteAttributeInteger('MotionLightOwnedTarget', 0);
        $this->WriteAttributeInteger('MotionLightLastDetection', 0);
        $this->SetTimerInterval('MotionLightTimer', 0);
        return true;
    }

    private function MotionLightingSwitch(int $id, bool $on): bool
    {
        try {
            if (IPS_GetVariable($id)['VariableType'] !== 0) throw new RuntimeException('Ziel ist keine Boolean-Variable.');
            if (!RequestAction($id, $on)) throw new RuntimeException('RequestAction fehlgeschlagen.');
            return true;
        } catch (Throwable $e) {
            $this->SendDebug('Bewegungsmelder', 'Schalten von ' . $id . ' fehlgeschlagen: ' . $e->getMessage(), 0);
            return false;
        }
    }
}
