<?php

declare(strict_types=1);

namespace PixelCoda\FeEditor\Api;

use PixelCoda\FeEditor\Event\AfterSaveEvent;
use PixelCoda\FeEditor\Event\BeforeSaveEvent;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\EventDispatcher\EventDispatcher;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SaveController
{
    public function handle(ServerRequestInterface $request): JsonResponse
    {
        try {
            $beUser = $GLOBALS['BE_USER'] ?? null;
            if (!$beUser instanceof BackendUserAuthentication || empty($beUser->user)) {
                return new JsonResponse(['error' => 'auth_required', 'message' => 'Backend user authentication required'], 401);
            }

            $parsedBody = $request->getParsedBody();
            /** @var array<string, mixed> $parsed */
            $parsed = is_array($parsedBody) ? $parsedBody : [];
            $formToken = $this->stringValue($parsed['formToken'] ?? '');

            $fp = GeneralUtility::makeInstance(FormProtectionFactory::class)->createFromRequest($request);
            if (!$fp->validateToken($formToken, 'pixelcoda-fe-editor', 'fe-editor-action')) {
                return new JsonResponse(['error' => 'invalid_token', 'message' => 'CSRF token validation failed'], 403);
            }

            $allowedTables = [
                'tt_content' => ['header', 'bodytext', 'subheader', 'frame_class', 'CType', 'colPos', 'pid', 'sorting', 'hidden'],
            ];

            $action = $this->stringValue($parsed['action'] ?? '');
            $table = $this->stringValue($parsed['table'] ?? '');
            $uid = $this->stringValue($parsed['uid'] ?? '');
            $field = $this->stringValue($parsed['field'] ?? '');
            $value = $this->stringValue($parsed['value'] ?? '');
            $target = $this->stringValue($parsed['target'] ?? '');
            $rawData = $this->stringValue($parsed['data'] ?? '');
            $rawCmd = $this->stringValue($parsed['cmd'] ?? '');

            /** @var array<string, array<int|string, array<string, mixed>>> $data */
            $data = [];
            /** @var array<string, array<int|string, array<string, mixed>>> $cmd */
            $cmd = [];
            /** @var array<string, mixed> $record */
            $record = [];
            $finalValue = null;
            $affectedPageIds = [];

            if ($action === 'delete' && $table !== '' && $uid !== '') {
                if (!array_key_exists($table, $allowedTables)) {
                    return new JsonResponse(['error' => 'table_not_allowed'], 400);
                }
                if (!$beUser->isAdmin() && !$beUser->check('tables_modify', $table)) {
                    return new JsonResponse(['error' => 'no_modify_permission'], 403);
                }
                $cmd[$table][(int) $uid] = ['delete' => 1];
            } elseif ($action === 'move' && $table !== '' && $uid !== '' && $target !== '') {
                if (!array_key_exists($table, $allowedTables)) {
                    return new JsonResponse(['error' => 'table_not_allowed'], 400);
                }
                if (!$beUser->isAdmin() && !$beUser->check('tables_modify', $table)) {
                    return new JsonResponse(['error' => 'no_modify_permission'], 403);
                }
                $cmd[$table][(int) $uid] = ['move' => (int) $target];
            } elseif ($table !== '' && $field !== '') {
                if (!array_key_exists($table, $allowedTables) || !in_array($field, $allowedTables[$table], true)) {
                    return new JsonResponse(['error' => 'field_not_allowed'], 400);
                }
                if (!ctype_digit($uid) || (int) $uid <= 0) {
                    return new JsonResponse(['error' => 'invalid_uid'], 400);
                }
                if (!$beUser->isAdmin() && !$beUser->check('tables_modify', $table)) {
                    return new JsonResponse(['error' => 'no_modify_permission'], 403);
                }

                /** @var array<string, mixed> $record */
                $record = BackendUtility::getRecord($table, (int) $uid) ?: [];
                if ($record === []) {
                    return new JsonResponse(['error' => 'record_not_found'], 404);
                }
                $recordPid = $this->intValue($record['pid'] ?? 0);
                if ($recordPid > 0) {
                    $affectedPageIds[$recordPid] = true;
                }

                $beforeSaveEvent = new BeforeSaveEvent($table, $field, $value, (int) $uid, $record);
                GeneralUtility::makeInstance(EventDispatcher::class)->dispatch($beforeSaveEvent);

                $finalValue = $beforeSaveEvent->getContent();
                $data[$table][(int) $uid] = [$field => $finalValue];
            }

            if ($rawData !== '') {
                $decoded = json_decode($rawData, true);
                if (!is_array($decoded)) {
                    return new JsonResponse(['error' => 'invalid_data_payload'], 400);
                }
                /** @var array<string, mixed> $decoded */
                foreach ($decoded as $t => $rows) {
                    if (!array_key_exists($t, $allowedTables) && is_array($rows) || !is_array($rows)) {
                        return new JsonResponse(['error' => 'table_not_allowed', 'table' => $t], 400);
                    }
                    if (!$beUser->isAdmin() && !$beUser->check('tables_modify', $t)) {
                        return new JsonResponse(['error' => 'no_modify_permission', 'table' => $t], 403);
                    }
                    foreach ($rows as $id => $fields) {
                        if (!is_array($fields)) {
                            return new JsonResponse(['error' => 'invalid_fields_payload'], 400);
                        }
                        $filtered = array_intersect_key($fields, array_flip($allowedTables[$t]));
                        if ($filtered !== []) {
                            $data[$t][$id] = $filtered;
                            $payloadPid = $this->intValue($filtered['pid'] ?? 0);
                            if ($payloadPid > 0) {
                                $affectedPageIds[$payloadPid] = true;
                            }
                            if (ctype_digit((string) $id)) {
                                $payloadRecord = BackendUtility::getRecord((string) $t, (int) $id, 'pid') ?: [];
                                $existingPid = $this->intValue($payloadRecord['pid'] ?? 0);
                                if ($existingPid > 0) {
                                    $affectedPageIds[$existingPid] = true;
                                }
                            }
                        }
                    }
                }
            }

            if ($rawCmd !== '') {
                $decodedCmd = json_decode($rawCmd, true);
                if (!is_array($decodedCmd)) {
                    return new JsonResponse(['error' => 'invalid_cmd_payload'], 400);
                }
                /** @var array<string, mixed> $decodedCmd */
                foreach ($decodedCmd as $t => $rows) {
                    if (!array_key_exists($t, $allowedTables) && is_array($rows) || !is_array($rows)) {
                        return new JsonResponse(['error' => 'cmd_table_not_allowed', 'table' => $t], 400);
                    }
                    if (!$beUser->isAdmin() && !$beUser->check('tables_modify', $t)) {
                        return new JsonResponse(['error' => 'no_modify_permission', 'table' => $t], 403);
                    }
                    foreach ($rows as $id => $ops) {
                        if (!is_array($ops)) {
                            return new JsonResponse(['error' => 'invalid_cmd_ops'], 400);
                        }
                        $allowedOps = array_intersect_key($ops, array_flip(['copy', 'move', 'delete']));
                        if ($allowedOps !== []) {
                            $cmd[$t][$id] = $allowedOps;
                        }
                    }
                }
            }

            if ($data === [] && $cmd === []) {
                return new JsonResponse(['error' => 'empty_payload'], 400);
            }

            /** @var DataHandler $dh */
            $dh = GeneralUtility::makeInstance(DataHandler::class);
            $dh->start($data, $cmd);
            $dh->process_datamap();
            $dh->process_cmdmap();

            if (!empty($dh->errorLog)) {
                return new JsonResponse([
                    'ok' => false,
                    'error' => 'datahandler_errors',
                    'errors' => $dh->errorLog,
                    'message' => 'DataHandler processing failed',
                ], 400);
            }

            if ($table !== '' && $field !== '' && $finalValue !== null) {
                $afterSaveEvent = new AfterSaveEvent($table, $field, $finalValue, (int) $uid, $record, true);
                GeneralUtility::makeInstance(EventDispatcher::class)->dispatch($afterSaveEvent);
            }

            $affectedPageIds = array_keys($affectedPageIds);
            if ($affectedPageIds !== []) {
                GeneralUtility::makeInstance(CacheManager::class)->flushCachesInGroupByTags(
                    'pages',
                    array_map(static fn(int $pageId): string => 'pageId_' . $pageId, $affectedPageIds)
                );
            }

            $result = [
                'ok' => true,
                'message' => 'Data saved successfully',
                'copymap' => $dh->copyMappingArray_merged,
                'clearedPageIds' => $affectedPageIds,
            ];
            return new JsonResponse($result, 200);
        } catch (\Exception $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'exception',
                'message' => 'An error occurred: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
