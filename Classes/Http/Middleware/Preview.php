<?php
declare(strict_types = 1);

namespace F7\Preview\Http\Middleware;

/*
 * This file is part of TYPO3 CMS extension review by F7.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use F7\Preview\Authentication\PreviewUserAuthentication;
use F7\Preview\Preview\PreviewUriBuilder;
use F7\Preview\Utility\PreviewUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Middleware to detect "preview mode" so that a hidden language is shown in the frontend
 */
class Preview implements MiddlewareInterface
{
    /**
     * @var Context
     */
    protected $context;

    public const REQUEST_ATTRIBUTE = 'tx_preview';


    public function __construct(?Context $context = null)
    {
        $this->context = $context ?? GeneralUtility::makeInstance(Context::class);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->context->getPropertyFromAspect('backend.user', 'isLoggedIn')) {
            return $handler->handle($request);
        }

        $hash = $this->findHashInRequest($request);
        if (empty($hash)) {
            return $handler->handle($request);
        }

        $language = $request->getAttribute('language', null);
        if (!$language instanceof SiteLanguage) {
            return $handler->handle($request);
        }

        PreviewUtility::removeOutdatedLinks();

        if (!$this->verifyHash($hash, $language)) {
            return $handler->handle($request);
        }

        $this->initializePreviewUser($language, $this->findTargetPid($hash));
        $response = $handler->handle($request);

        // If the GET parameter PreviewUriBuilder::PARAMETER_NAME is set, then a cookie is set for the next request
        if ($request->getQueryParams()[PreviewUriBuilder::PARAMETER_NAME] ?? false) {
            /** @var NormalizedParams $normalizedParams */
            $normalizedParams = $request->getAttribute('normalizedParams');
            $cookie = new Cookie(
                name: PreviewUriBuilder::PARAMETER_NAME,
                value: $hash,
                path: $normalizedParams->getSitePath(),
                secure: true,
                httpOnly: true
            );
            return $response->withAddedHeader('Set-Cookie', $cookie->__toString());
        }
        return $response;
    }

    /**
     * Looks for the PreviewUriBuilder::PARAMETER_NAME in the QueryParams and Cookies
     *
     * @param ServerRequestInterface $request
     * @return string
     */
    protected function findHashInRequest(ServerRequestInterface $request): string
    {
        return $request->getQueryParams()[PreviewUriBuilder::PARAMETER_NAME] ?? $request->getCookieParams()[PreviewUriBuilder::PARAMETER_NAME] ?? '';
    }

    protected function setCookie(string $inputCode, NormalizedParams $normalizedParams): void
    {
        setcookie(PreviewUriBuilder::PARAMETER_NAME, $inputCode, 0, $normalizedParams->getSitePath(), '', true, true);
    }



    /**
     * Creates a preview user and sets the current page ID (for accessing the page)
     */
    protected function initializePreviewUser(SiteLanguage $language, int $targetPid): void
    {
        $previewUser = GeneralUtility::makeInstance(PreviewUserAuthentication::class, $language);
        $previewUser->setWebmounts([$targetPid]);
        $GLOBALS['BE_USER'] = $previewUser;

        $this->setBackendUserAspect($previewUser);
    }

    /**
     * Register the backend user as aspect
     */
    protected function setBackendUserAspect(BackendUserAuthentication $user = null): void
    {
        debug($user, 'user');
        $this->context->setAspect(
            'backend.user',
            GeneralUtility::makeInstance(UserAspect::class, $user)
        );

    }

    /**
     * Looks for the hash in the table tx_preview
     * Must not be expired yet.
     */
    protected function verifyHash(string $hash, SiteLanguage $language): bool
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_preview');
        $row = $queryBuilder
            ->select('*')
            ->from('tx_preview')
            ->where(
                $queryBuilder->expr()->eq(
                    'hash',
                    $queryBuilder->createNamedParameter($hash)
                ),
                $queryBuilder->expr()->gt(
                    'endtime',
                    $queryBuilder->createNamedParameter(
                        $this->context->getPropertyFromAspect('date', 'timestamp'),
                        Connection::PARAM_INT
                    )
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (empty($row)) {
            return false;
        }

        return (int)$row['sys_language_uid'] === $language->getLanguageId();
    }


    /**
     * @param string $hash
     * @return int
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    protected function findTargetPid(string $hash): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_preview');
        return $queryBuilder
            ->select('pid')
            ->from('tx_preview')
            ->where(
                $queryBuilder->expr()->eq(
                    'hash',
                    $queryBuilder->createNamedParameter($hash)
                ),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne() ?? 0;
    }

    /**
     * Simulate dates for preview functionality
     * When previewing a time restricted page from the backend, the parameter ADMCMD_simTime it added containing
     * a timestamp with the time to preview. The globals 'SIM_EXEC_TIME' and 'SIM_ACCESS_TIME' and the 'DateTimeAspect'
     * are used to simulate rendering at that point in time.
     * Ideally the global access is removed in future versions.
     * This functionality needs to be loaded after BackendAuthenticator as it is only relevant for
     * logged in backend users and needs to be done before any page resolving starts.
     *
     * @param ServerRequestInterface $request
     * @return bool
     */
    protected function simulateDate(ServerRequestInterface $request): bool
    {
        /* $pageId = $GLOBALS['TSFE']->id;
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');
        $row = $queryBuilder
            ->select('starttime, endtime')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter(
                        $pageId,
                        \PDO::PARAM_INT
                    )
                )
            )
            ->setMaxResults(1)
            ->execute()
            ->fetch();

        */

        $queryTime = $request->getQueryParams()['ADMCMD_simTime'] ?? false;
        if (!$queryTime) {
            return false;
        }

        $simulatedDate = new \DateTimeImmutable('@' . $queryTime);

        $GLOBALS['SIM_EXEC_TIME'] = $queryTime;
        $GLOBALS['SIM_ACCESS_TIME'] = $queryTime - $queryTime % 60;
        $this->context->setAspect(
            'date',
            GeneralUtility::makeInstance(
                DateTimeAspect::class,
                $simulatedDate
            )
        );
        return true;
    }
}
