<?php foreach ($activeRentals as $rental): ?>
    <tr>
        <td class="px-6 py-4"><?php echo htmlspecialchars($rental['ToolName']); ?></td>
        <td class="px-6 py-4">
            <?php echo htmlspecialchars($rental['OwnerFirstName'] . ' ' . $rental['OwnerLastName']); ?>
        </td>
        <td class="px-6 py-4">
            <?php echo date('M d, Y', strtotime($rental['RentalStartDate'])); ?> - 
            <?php echo date('M d, Y', strtotime($rental['RentalEndDate'])); ?>
        </td>
        <td class="px-6 py-4">
            <span class="countdown-timer" data-endtime="<?php echo htmlspecialchars($rental['RentalEndDate']); ?>"></span>
        </td>
        <td class="px-6 py-4">
            <span class="px-2 py-1 text-sm rounded-full 
                <?php echo $rental['Status'] === 'Approved' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                <?php echo $rental['Status']; ?>
            </span>
        </td>
        <td class="px-6 py-4">
            <?php if (isset($rental['PaymentAmount'])): ?>
                $<?php echo number_format($rental['PaymentAmount'], 2); ?>
                <span class="ml-2 px-2 py-1 text-xs rounded-full 
                    <?php echo $rental['PaymentStatus'] === 'Paid' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                    <?php echo $rental['PaymentStatus'] ?? 'Pending'; ?>
                </span>
            <?php else: ?>
                Pending
            <?php endif; ?>
        </td>
        <td class="px-6 py-4">
            <?php if ($rental['Status'] === 'Approved' && ($rental['PaymentStatus'] !== 'Paid')): ?>
                <a href="pay_rental.php?rental_id=<?php echo $rental['RentalID']; ?>" 
                   class="bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700 block text-center">Pay Now</a>
            <?php elseif ($rental['Status'] === 'Approved' && ($rental['PaymentStatus'] === 'Paid')): ?>
                <a href="return_tool.php?id=<?php echo $rental['RentalID']; ?>" 
                   class="inline-block bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700">
                    Mark as Returned
                </a>
            <?php else: ?>
                -
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
