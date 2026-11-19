<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Exception;

/**
 * The cashbook was changed since the client loaded it.
 */
final class ConflictException extends \RuntimeException {
}
