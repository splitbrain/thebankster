<?php

namespace splitbrain\TheBankster\CLI;

use splitbrain\phpcli\Colors;
use splitbrain\phpcli\Exception;
use splitbrain\phpcli\Options;
use splitbrain\phpcli\PSR3CLI;
use splitbrain\phpcli\PSR3CLIv3;
use splitbrain\phpcli\TableFormatter;
use splitbrain\TheBankster\Container;
use splitbrain\TheBankster\Entity\Rule;

class RuleCLI extends PSR3CLIv3
{

    /**
     * Register options and arguments on the given $options object
     *
     * @param Options $options
     * @return void
     */
    protected function setup(Options $options)
    {
        $options->setHelp('Manage categorization rules');

        $options->registerCommand('list', 'List all available rules');

        $options->registerCommand('add', 'Add a new rule');
        $options->registerArgument('cat', 'The category ID this rules applies to', 'id', 'add');
        $options->registerOption('account', 'Only apply this rule to this account', null, 'account', 'add');
        $options->registerOption('debit', 'Only match spending (-1) or income (+1)', null, '-1|+1', 'add');
        $options->registerOption('desc', 'Match this against the transaction\'s description', null, 'match', 'add');
        $options->registerOption('xname', 'Match this against the transaction issuer\'s name', null, 'match', 'add');
        $options->registerOption('xbank', 'Match this against the transaction issuer\'s bank', null, 'match', 'add');
        $options->registerOption('xaccount', 'Match this against the transaction issuer\'s account', null, 'match', 'add');

        $options->registerCommand('change', 'Change an existing rule');
        $options->registerArgument('id', 'ID of the rule to change', true, 'change');
        $options->registerOption('cat', 'Change the category of the rule', null, 'id', 'change');
        $options->registerOption('account', 'Only apply this rule to this account', null, 'account', 'change');
        $options->registerOption('debit', 'Only match spending (neg) or income (pos)', null, 'neg|pos', 'change');
        $options->registerOption('desc', 'Match this against the transaction\'s description', null, 'match', 'change');
        $options->registerOption('xname', 'Match this against the transaction issuer\'s name', null, 'match', 'change');
        $options->registerOption('xbank', 'Match this against the transaction issuer\'s bank', null, 'match', 'change');
        $options->registerOption('xaccount', 'Match this against the transaction issuer\'s account', null, 'match', 'change');

        $options->registerCommand('del', 'Delete an existing rule');
        $options->registerArgument('id', 'ID of the rule to change', true, 'del');

        $options->registerCommand('preview', 'Show all matching transactions');
        $options->registerArgument('id', 'ID of the rule to preview', true, 'preview');

        $options->registerCommand('enable', 'Enable a rule and apply it to all matching transactions');
        $options->registerArgument('id', 'ID of the rule to enable', true, 'enable');
    }

    /**
     * Your main program
     *
     * Arguments and options have been parsed when this is run
     *
     * @param Options $options
     * @return void
     * @throws Exception
     */
    protected function main(Options $options)
    {
        $container = Container::getInstance();
        $container->setLogger($this);
        $db = $container->db;

        $args = $options->getArgs();
        switch ($options->getCmd()) {
            case 'list':
                /** @var Rule[] $rules */
                $rules = $db->fetch(Rule::class)->all();
                $tf = new TableFormatter($this->colors);

                echo $tf->format(
                    [3, 3, 5, 25, '*'],
                    ['ID', 'On', 'CatID', 'Category', 'Matches'],
                    [Colors::C_BROWN, Colors::C_BROWN, Colors::C_BROWN, Colors::C_BROWN, Colors::C_BROWN]
                );

                foreach ($rules as $rule) {
                    echo $tf->format(
                        [3, 3, 5, 25, '*'],
                        [
                            $rule->id,
                            $rule->enabled
                                ? $this->colors->wrap('✓', Colors::C_GREEN)
                                : $this->colors->wrap('✘', Colors::C_RED),
                            $rule->category->id,
                            $rule->category->getFullName(),
                            $rule->displayRules(),
                        ]
                    );
                }

                break;

            case 'add':
                $rule = new Rule();
                $rule->category_id = (int)$args[0];
                $this->applyOptions($rule, $options);
                $rule = $rule->save();
                $this->success('Saved rule {id}', ['id' => $rule->id]);
                break;

            case 'change':
                /** @var Rule $rule */
                $rule = $db->fetch(Rule::class, $args[0]);
                if ($rule === null) throw new Exception('No such rule', -1);
                $this->applyOptions($rule, $options);
                $rule->save();
                $this->success('Updated rule');
                break;

            case 'del':
                $rule = $db->fetch(Rule::class, $args[0]);
                if ($rule === null) throw new Exception('No such rule', -1);
                $db->delete($rule);
                $this->success('Rule deleted');
                break;

            case 'preview':
                /** @var Rule $rule */
                $rule = $db->fetch(Rule::class, $args[0]);
                if ($rule === null) throw new Exception('No such rule', -1);

                $query = $rule->matchTransactionsQuery();
                $txs = $query->all();
                foreach ($txs as $tx) echo (string)$tx . "\n";
                break;

            case 'enable';
                /** @var Rule $rule */
                $rule = $db->fetch(Rule::class, $args[0]);
                if ($rule === null) throw new Exception('No such rule', -1);

                $rule->enabled = 1;
                $rule->save();

                $query = $rule->matchTransactionsQuery();
                $txs = $query->all();
                foreach ($txs as $tx) {
                    $tx->categoryId = $rule->category_id;
                    $tx->save();
                    echo '.';
                }
                echo "\n";
                $this->success('Rule enabled');
                break;

            default:
                echo $options->help();


        }
    }

    /**
     * Apply the given options to the given rule
     *
     * @param Rule $rule
     * @param Options $options
     * @throws \Exception
     */
    protected function applyOptions($rule, $options)
    {
        $ok = false;
        if ($options->getOpt('cat') !== false) {
            $rule->categoryId = $options->getOpt('cat');
            $ok = true;
        }
        if ($options->getOpt('account') !== false) {
            $rule->account = $options->getOpt('account');
            $ok = true;
        }
        if ($options->getOpt('debit') !== false) {
            $deb = $options->getOpt('debit');
            if($deb === 'neg') {
                $rule->debit = -1;
            } elseif ($deb === 'pos') {
                $rule->debit = 1;
            } else {
                throw new Exception('Debit option requires neg or pos as agrument');
            }
            $ok = true;
        }
        if ($options->getOpt('desc') !== false) {
            $rule->description = $options->getOpt('desc');
            $ok = true;
        }
        if ($options->getOpt('xname') !== false) {
            $rule->xName = $options->getOpt('xname');
            $ok = true;
        }
        if ($options->getOpt('xbank') !== false) {
            $rule->xBank = $options->getOpt('xbank');
            $ok = true;
        }
        if ($this->options->getOpt('xaccount') !== false) {
            $rule->xAcct = $this->options->getOpt('xaccount');
            $ok = true;
        }

        if (!$ok) throw new \Exception('You ned to provide at least one option');
    }
}
