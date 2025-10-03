<?php

namespace splitbrain\TheBankster\CLI;

use splitbrain\phpcli\Colors;
use splitbrain\phpcli\Exception;
use splitbrain\phpcli\Options;
use splitbrain\phpcli\PSR3CLI;
use splitbrain\phpcli\PSR3CLIv3;
use splitbrain\phpcli\TableFormatter;
use splitbrain\TheBankster\Container;
use splitbrain\TheBankster\Entity\Category;


class CategoryCLI extends PSR3CLIv3
{

    /**
     * Register options and arguments on the given $options object
     *
     * @param Options $options
     * @return void
     */
    protected function setup(Options $options)
    {
        $options->setHelp('Manage categories');

        $options->registerCommand('list', 'Show all the current categories');

        $options->registerCommand('add', 'Add a new category');
        $options->registerArgument('top', 'The top level category name', true, 'add');
        $options->registerArgument('cat', 'The category name', true, 'add');

        $options->registerCommand('del', 'Delete a category identified by it\'s ID');
        $options->registerArgument('id', 'The category\'s ID', true, 'del');

        $options->registerCommand('change', 'Change a category');
        $options->registerArgument('id', 'The category\'s ID', true, 'change');
        $options->registerArgument('top', 'The top level category name', true, 'change');
        $options->registerArgument('cat', 'The category name', true, 'change');

        $options->registerCommand('rename', 'Rename a top level category');
        $options->registerArgument('old', 'The old top level category name', true, 'rename');
        $options->registerArgument('new', 'The new top level category name', true, 'rename');
    }

    /**
     * Your main program
     *
     * Arguments and options have been parsed when this is run
     *
     * @param Options $options
     * @return void
     */
    protected function main(Options $options)
    {
        $container = Container::getInstance();
        $container->setLogger($this);
        $db = $container->db;

        $args = $options->getArgs();
        switch ($options->getCmd()) {
            case 'list':
                $tf = new TableFormatter($this->colors);
                echo $tf->format(
                    ['10', '50', '*'],
                    ['ID', 'Top Level', 'Name'],
                    [Colors::C_BROWN, Colors::C_BROWN, Colors::C_BROWN]
                );

                $items = $db->fetch(Category::class)->all();
                foreach ($items as $item) {
                    echo $tf->format(
                        ['10', '50', '*'],
                        [$item->id, $item->top, $item->label]
                    );
                }
                break;

            case 'add':
                $cat = new Category([
                    'top' => $args[0],
                    'label' => $args[1]
                ]);
                $new = $cat->save();
                $this->success('Category {id} added.', ['id' => $new->id]);
                break;

            case 'change':
                $cat = $db->fetch(Category::class, $args[0]);
                if ($cat === null) throw new Exception('No such category', -1);
                $cat->fill([
                    'top' => $args[1],
                    'label' => $args[2]
                ]);
                $cat->save();
                $this->success('Category changed');
                break;

            case 'del';
                $cat = $db->fetch(Category::class, $args[0]);
                if ($cat === null) throw new Exception('No such category', -1);
                $db->delete($cat);
                $this->success('Category deleted');
                break;

            case 'rename':
                $ok = Category::renameTop($args[0], $args[1]);
                if ($ok) {
                    $this->success('Top level renamed for {num} categories', ['num' => $ok]);
                } else {
                    $this->error('No matching top level found');
                }
                break;

            default:
                echo $options->help();
        }
    }
}
