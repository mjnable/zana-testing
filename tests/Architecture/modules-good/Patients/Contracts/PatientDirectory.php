<?php

namespace Modules\Patients\Contracts;

interface PatientDirectory
{
    public function find(string $id): ?PatientSnapshot;
}
