<?php

declare(strict_types=1);

namespace App\Console;

use App\Domain\User\Role;
use App\Domain\User\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Yii\Console\ExitCode;

/**
 * `./yii user:create <email> <name> --role=author --password=…`
 *
 * There is no sign-up screen: accounts are created here (the first admin included). Without
 * `--password` the password is prompted for, hidden, so it does not end up in shell history.
 */
#[AsCommand(
    name: 'user:create',
    description: 'Creates a user account',
)]
final class CreateUserCommand extends Command
{
    public const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address (the login)')
            ->addArgument('name', InputArgument::REQUIRED, 'Display name')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'One of: ' . implode(', ', Role::values()), Role::Author->value)
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password (prompted for when omitted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = trim((string) $input->getArgument('email'));
        $name = trim((string) $input->getArgument('name'));
        $role = Role::tryFrom((string) $input->getOption('role'));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $io->error('Invalid email address.');
            return ExitCode::DATAERR;
        }
        if ($name === '' || mb_strlen($name) > 255) {
            $io->error('The name must be 1 to 255 characters.');
            return ExitCode::DATAERR;
        }
        if ($role === null) {
            $io->error('The role must be one of: ' . implode(', ', Role::values()) . '.');
            return ExitCode::DATAERR;
        }
        if ($this->users->emailExists($email)) {
            $io->error('A user with this email already exists.');
            return ExitCode::DATAERR;
        }

        $password = $input->getOption('password');
        if (!is_string($password)) {
            $question = (new Question('Password'))->setHidden(true)->setHiddenFallback(false);
            $password = $io->askQuestion($question);
        }
        if (!is_string($password) || mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $io->error('The password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.');
            return ExitCode::DATAERR;
        }

        $user = $this->users->create($name, $email, $password, $role);
        $io->success(sprintf('Created %s "%s" <%s> with id %d.', $role->value, $user->name, $user->email, $user->id));

        return ExitCode::OK;
    }
}
