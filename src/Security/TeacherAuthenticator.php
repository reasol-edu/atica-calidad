<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Teacher;
use App\Service\SenecaAuthenticatorService;
use Random\RandomException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use RuntimeException;

final class TeacherAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    private const ERROR_MISSING_CREDENTIALS = 'auth.missing_credentials';
    private const ERROR_INVALID_CREDENTIALS = 'auth.invalid_credentials';
    private const ERROR_USER_INACTIVE = 'auth.user_inactive';
    private const ERROR_EXTERNAL_UNAVAILABLE = 'auth.external_unavailable';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SenecaAuthenticatorService $senecaAuthenticator,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $username = trim((string) $request->request->get('_username', ''));
        $password = (string) $request->request->get('_password', '');
        $csrfToken = (string) $request->request->get('_csrf_token');

        if ($username === '' || $password === '') {
            throw new CustomUserMessageAuthenticationException(self::ERROR_MISSING_CREDENTIALS);
        }

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $username);

        return new Passport(
            new UserBadge($username),
            new CustomCredentials(function (mixed $plainPassword, UserInterface $user): bool {
                if (!$user instanceof Teacher || !is_string($plainPassword)) {
                    throw new CustomUserMessageAuthenticationException(self::ERROR_INVALID_CREDENTIALS);
                }

                if (!$user->isActive()) {
                    throw new CustomUserMessageAuthenticationException(self::ERROR_USER_INACTIVE);
                }

                if ($user->isExternal()) {
                    if (!$this->senecaAuthenticator->isEnabled()) {
                        throw new CustomUserMessageAuthenticationException(self::ERROR_EXTERNAL_UNAVAILABLE);
                    }

                    try {
                        if ($this->senecaAuthenticator->checkUserCredentials($user->getUsername(), $plainPassword)) {
                            return true;
                        }
                    } catch (RandomException|RuntimeException) {
                        throw new CustomUserMessageAuthenticationException(self::ERROR_EXTERNAL_UNAVAILABLE);
                    }

                    throw new CustomUserMessageAuthenticationException(self::ERROR_INVALID_CREDENTIALS);
                }

                $hashedPassword = $user->getPassword();
                if ($hashedPassword === null || $hashedPassword === '') {
                    throw new CustomUserMessageAuthenticationException(self::ERROR_INVALID_CREDENTIALS);
                }

                if (!$this->passwordHasher->isPasswordValid($user, $plainPassword)) {
                    throw new CustomUserMessageAuthenticationException(self::ERROR_INVALID_CREDENTIALS);
                }

                return true;
            }, $password),
            [
                new CsrfTokenBadge('authenticate', $csrfToken),
            ]
        );
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate('app_login');
    }

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_route') === 'app_login' && $request->isMethod('POST');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $targetPath = $this->getTargetPath($request->getSession(), $firewallName);
        if ($targetPath !== null) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_dashboard'));
    }

}

