<?php declare(strict_types = 1);

namespace Churn\Command;

use Churn\Configuration\Config;
use Churn\Factories\ResultsRendererFactory;
use Churn\Logic\ResultsLogic;
use Churn\Managers\FileManager;
use Churn\Process\Observer\OnSuccess;
use Churn\Process\Observer\OnSuccessNull;
use Churn\Process\Observer\OnSuccessProgress;
use Churn\Process\ProcessFactory;
use Churn\Process\ProcessHandlerFactory;
use Churn\Results\ResultCollection;
use function count;
use function file_get_contents;
use function fopen;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Yaml\Yaml;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RunCommand extends Command
{
    public const LOGO ="
    ___  _   _  __  __  ____  _  _     ____  _   _  ____
   / __)( )_( )(  )(  )(  _ \( \( )___(  _ \( )_( )(  _ \
  ( (__  ) _ (  )(__)(  )   / )  ((___))___/ ) _ (  )___/
   \___)(_) (_)(______)(_)\_)(_)\_)   (__)  (_) (_)(__)
";

    /**
     * The results logic.
     * @var ResultsLogic
     */
    private $resultsLogic;

    /**
     * The process handler factory.
     * @var ProcessHandlerFactory
     */
    private $processHandlerFactory;

    /**
     * The renderer factory.
     * @var ResultsRendererFactory
     */
    private $renderFactory;

    /**
     * ChurnCommand constructor.
     * @param ResultsLogic           $resultsLogic          The results logic.
     * @param ProcessHandlerFactory  $processHandlerFactory The process handler factory.
     * @param ResultsRendererFactory $renderFactory         The Results Renderer Factory.
     */
    public function __construct(
        ResultsLogic $resultsLogic,
        ProcessHandlerFactory $processHandlerFactory,
        ResultsRendererFactory $renderFactory
    ) {
        parent::__construct();
        $this->resultsLogic = $resultsLogic;
        $this->processHandlerFactory = $processHandlerFactory;
        $this->renderFactory = $renderFactory;
    }

    /**
     * Configure the command
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('run')
            ->addArgument('paths', InputArgument::IS_ARRAY, 'Path to source to check.')
            ->addOption('configuration', 'c', InputOption::VALUE_OPTIONAL, 'Path to the configuration file', 'churn.yml')  // @codingStandardsIgnoreLine
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'The output format to use', 'text')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'The path where to write the result')
            ->addOption('progress', 'p', InputOption::VALUE_NONE, 'Show progress bar')
            ->setDescription('Check files')
            ->setHelp('Checks the churn on the provided path argument(s).');
    }

    /**
     * Execute the command
     * @param InputInterface  $input  Input.
     * @param OutputInterface $output Output.
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->displayLogo($input, $output);
        $content = (string) @file_get_contents($input->getOption('configuration'));
        $config = Config::create(Yaml::parse($content) ?? []);
        $filesCollection = (new FileManager($config->getFileExtensions(), $config->getFilesToIgnore()))
            ->getPhpFiles($this->getDirectoriesToScan($input, $config->getDirectoriesToScan()));
        $completedProcesses = $this->processHandlerFactory->getProcessHandler($config)->process(
            $filesCollection,
            new ProcessFactory($config->getCommitsSince()),
            $this->getOnSuccessObserver($input, $output, $filesCollection->count())
        );
        $resultCollection = $this->resultsLogic->process(
            $completedProcesses,
            $config->getMinScoreToShow(),
            $config->getFilesToShow()
        );
        $this->writeResult($input, $output, $resultCollection);
        return 0;
    }

    /**
     * Get the directories to scan.
     * @param InputInterface $input          Input Interface.
     * @param array          $dirsConfigured The directories configured to scan.
     * @throws InvalidArgumentException If paths argument invalid.
     * @return array When no directories to scan found.
     */
    private function getDirectoriesToScan(InputInterface $input, array $dirsConfigured): array
    {
        $dirsProvidedAsArgs = $input->getArgument('paths');
        if (count($dirsProvidedAsArgs) > 0) {
            return $dirsProvidedAsArgs;
        }

        if (count($dirsConfigured) > 0) {
            return $dirsConfigured;
        }

        throw new InvalidArgumentException(
            'Provide the directories you want to scan as arguments, ' .
            'or configure them under "directoriesToScan" in your churn.yml file.'
        );
    }

    /**
     * @param InputInterface  $input      Input.
     * @param OutputInterface $output     Output.
     * @param integer         $totalFiles Total number of files to process.
     * @return OnSuccess
     */
    private function getOnSuccessObserver(InputInterface $input, OutputInterface $output, int $totalFiles): OnSuccess
    {
        if ((bool)$input->getOption('progress')) {
            $progressBar = new ProgressBar($output, $totalFiles);
            $progressBar->start();
            return new OnSuccessProgress($progressBar);
        }

        return new OnSuccessNull();
    }

    /**
     * @param InputInterface  $input  Input.
     * @param OutputInterface $output Output.
     * @return void
     */
    private function displayLogo(InputInterface $input, OutputInterface $output): void
    {
        if ($input->getOption('format') !== 'text' && empty($input->getOption('output'))) {
            return;
        }

        $output->writeln(self::LOGO);
    }

    /**
     * @param InputInterface   $input            Input.
     * @param OutputInterface  $output           Output.
     * @param ResultCollection $resultCollection The result to write.
     * @return void
     */
    private function writeResult(InputInterface $input, OutputInterface $output, ResultCollection $resultCollection): void
    {
        if ((bool)$input->getOption('progress')) {
            $output->writeln("\n");
        }
        if (!empty($input->getOption('output'))) {
            $output = new StreamOutput(fopen($input->getOption('output'), 'w+'), OutputInterface::VERBOSITY_NORMAL, false);
        }

        $renderer = $this->renderFactory->getRenderer($input->getOption('format'));
        $renderer->render($output, $resultCollection);
    }
}