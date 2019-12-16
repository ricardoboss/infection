<?php
/**
 * This code is licensed under the BSD 3-Clause License.
 *
 * Copyright (c) 2017, Maks Rafalko
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * * Redistributions of source code must retain the above copyright notice, this
 *   list of conditions and the following disclaimer.
 *
 * * Redistributions in binary form must reproduce the above copyright notice,
 *   this list of conditions and the following disclaimer in the documentation
 *   and/or other materials provided with the distribution.
 *
 * * Neither the name of the copyright holder nor the names of its
 *   contributors may be used to endorse or promote products derived from
 *   this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

declare(strict_types=1);

namespace Infection\Mutation;

use function assert;
use function count;
use Infection\EventDispatcher\EventDispatcherInterface;
use Infection\Events\MutableFileProcessed;
use Infection\Events\MutationGeneratingFinished;
use Infection\Events\MutationGeneratingStarted;
use Infection\Mutation;
use Infection\Mutator\Util\Mutator;
use Infection\TestFramework\Coverage\LineCodeCoverage;
use Infection\Visitor\MutationsCollectorVisitor;
use function is_string;
use PhpParser\NodeVisitorAbstract;
use Symfony\Component\Finder\SplFileInfo;

/**
 * @internal
 */
final class MutationGenerator
{
    /**
     * @var SplFileInfo[]
     */
    private $sourceFiles;

    /**
     * @var LineCodeCoverage
     */
    private $codeCoverageData;

    /**
     * @var Mutator[]
     */
    private $mutators;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var FileParser
     */
    private $parser;
    private $traverserFactory;

    /**
     * @param SplFileInfo[] $sourceFiles
     */
    public function __construct(
        array $sourceFiles,
        LineCodeCoverage $codeCoverageData,
        array $mutators,
        EventDispatcherInterface $eventDispatcher,
        FileParser $parser,
        NodeTraverserFactory $traverserFactory
    ) {
        $this->sourceFiles = $sourceFiles;
        $this->codeCoverageData = $codeCoverageData;
        $this->mutators = $mutators;
        $this->eventDispatcher = $eventDispatcher;
        $this->parser = $parser;
        $this->traverserFactory = $traverserFactory;
    }

    /**
     * @param bool $onlyCovered mutate only covered by tests lines of code
     * @param NodeVisitorAbstract[] $extraNodeVisitors
     *
     * @return Mutation[]
     */
    public function generate(bool $onlyCovered, array $extraNodeVisitors = []): array
    {
        $allFilesMutations = [[]];

        $this->eventDispatcher->dispatch(new MutationGeneratingStarted(count($this->sourceFiles)));

        foreach ($this->sourceFiles as $file) {
            $allFilesMutations[] = $this->getMutationsFromFile($file, $onlyCovered, $extraNodeVisitors);

            $this->eventDispatcher->dispatch(new MutableFileProcessed());
        }

        $this->eventDispatcher->dispatch(new MutationGeneratingFinished());

        return array_merge(...$allFilesMutations);
    }

    /**
     * @param bool $onlyCovered mutate only covered by tests lines of code
     * @param NodeVisitorAbstract[] $extraNodeVisitors extra visitors to influence to mutation collection process
     *
     * @return Mutation[]
     */
    private function getMutationsFromFile(SplFileInfo $file, bool $onlyCovered, array $extraNodeVisitors): array
    {
        if ($onlyCovered && !$this->hasTests($file)) {
            return [];
        }

        $initialStatements = $this->parser->parse($file);

        $filePath = $file->getRealPath();
        assert(is_string($filePath));

        $mutationsCollectorVisitor = new MutationsCollectorVisitor(
            $this->mutators,
            $filePath,
            $initialStatements,
            $this->codeCoverageData,
            $onlyCovered
        );

        $extraNodeVisitors[10] = $mutationsCollectorVisitor;

        $traverser = $this->traverserFactory->create($extraNodeVisitors);

        $traverser->traverse($initialStatements);

        return $mutationsCollectorVisitor->getMutations();
    }

    private function hasTests(SplFileInfo $file): bool
    {
        $filePath = $file->getRealPath();
        assert(is_string($filePath));

        return $this->codeCoverageData->hasTests($filePath);
    }
}