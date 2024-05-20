<?php
namespace phasync;

use phasync\Internal\SelectManager;

/**
 * Selectable objects can be used together with {@see phasync::select()} to wait for
 * multiple events simultaneously.
 * 
 * @package phasync
 */
interface SelectableInterface {

    /**
     * Returns the select manager which must be notified whenever the object
     * becomes selectable.
     * 
     * @return SelectManager 
     */
    public function getSelectManager(): SelectManager;

    /**
     * Returns true when accessing the object will not block (for example if
     * data is available or if the source is closed or in a failed state).
     * 
     * @return bool 
     */
    public function selectWillBlock(): bool;
}