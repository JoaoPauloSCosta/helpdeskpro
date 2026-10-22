<?php

namespace App\Actions\SlaPolicies;

use RuntimeException;

/** A prioridade já tem uma política ativa; uma segunda tornaria a seleção ambígua (RF-15.3). */
class ActiveSlaPolicyConflict extends RuntimeException {}
