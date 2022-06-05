
    /**
     * Get all versioned database classes
     *
     * @return array
     */
    private function _getAllVersionedDataClasses()
    {
        $all_classes       = ClassInfo::subclassesFor(DataObject::class);
        $versioned_classes = [];
        foreach ($all_classes as $c) {
            if (DataObject::has_extension($c, Versioned::class)) {
                array_push($versioned_classes, $c);
            }
        }

        return array_reverse($versioned_classes);
    }
