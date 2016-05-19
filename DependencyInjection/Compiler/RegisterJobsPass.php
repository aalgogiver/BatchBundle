<?php

namespace Akeneo\Bundle\BatchBundle\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Parser as YamlParser;

/**
 * Read the batch_jobs.yml file or batch_jobs folder of the connectors to register the jobs
 *
 * @author    Gildas Quemener <gildas.quemener@gmail.com>
 * @copyright 2013 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/MIT MIT
 */
class RegisterJobsPass implements CompilerPassInterface
{
    /** @var YamlParser */
    protected $yamlParser;

    /** @var NodeInterface */
    protected $jobsConfig;

    /**
     * @param YamlParser $yamlParser
     */
    public function __construct($yamlParser = null)
    {
        $this->yamlParser = $yamlParser ?: new YamlParser();
    }

    /**
     * @param ContainerBuilder $container
     */
    public function process(ContainerBuilder $container)
    {
        $registry = $container->getDefinition('akeneo_batch.connectors');
        $configFileDir =
            DIRECTORY_SEPARATOR . 'Resources' .
            DIRECTORY_SEPARATOR . 'config' .
            DIRECTORY_SEPARATOR . 'batch_jobs';


        foreach ($container->getParameter('kernel.bundles') as $bundle) {
            $reflClass = new \ReflectionClass($bundle);
            if (false === $bundleDir = dirname($reflClass->getFileName())) {
                continue;
            }

            $configFiles = [];
            if (is_dir($configDir = $bundleDir . $configFileDir)) {
                $finder = Finder::create()->files()->in($configDir);
                foreach ($finder as $file) {
                    if (is_file($file->getPathname())) {
                        $configFiles[] = $file->getPathname();
                    }
                }
            } elseif (is_file($configFile = $bundleDir . $configFileDir . '.yml')) {
                $configFiles[] = $configFile;
            }

            foreach ($configFiles as $configFile) {
                $container->addResource(new FileResource($configFile));
                $this->registerJobs($registry, $configFile);
            }
        }
    }

    /**
     * @param Definition $definition
     * @param string     $configFile
     */
    protected function registerJobs(Definition $definition, $configFile)
    {
        $config = $this->processConfig(
            $this->yamlParser->parse(
                file_get_contents($configFile)
            )
        );

        foreach ($config['jobs'] as $jobName => $job) {
            foreach ($job['steps'] as $stepName => $step) {
                $services = array();
                foreach ($step['services'] as $setter => $serviceId) {
                    $services[$setter]= new Reference($serviceId);
                }

                $parameters = array();
                foreach ($step['parameters'] as $setter => $value) {
                    $parameters[$setter] = $value;
                }

                $definition->addMethodCall(
                    'addStepToJob',
                    array(
                        $config['name'],
                        $job['type'],
                        $jobName,
                        $stepName,
                        $step['class'],
                        $services,
                        $parameters
                    )
                );
            }
        }
    }

    /**
     * @param array $config
     *
     * @return array
     */
    protected function processConfig(array $config)
    {
        $processor = new Processor();
        if (!$this->jobsConfig) {
            $this->jobsConfig = $this->getJobsConfigTree();
        }

        return $processor->process($this->jobsConfig, $config);
    }

    /**
     * @return NodeInterface
     */
    protected function getJobsConfigTree()
    {
        $treeBuilder = new TreeBuilder();
        $root = $treeBuilder->root('connector');
        $root
            ->children()
                ->scalarNode('name')->end()
                ->arrayNode('jobs')
                    ->useAttributeAsKey('name')
                    ->prototype('array')
                        ->children()
                            ->scalarNode('type')->end()
                            ->arrayNode('steps')
                                ->prototype('array')
                                    ->children()
                                        ->scalarNode('class')
                                            ->defaultValue('Akeneo\Component\Batch\Step\ItemStep')
                                        ->end()
                                        ->arrayNode('services')
                                            ->prototype('scalar')->end()
                                        ->end()
                                        ->arrayNode('parameters')
                                            ->prototype('scalar')->end()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ->end();

        return $treeBuilder->buildTree();
    }
}
