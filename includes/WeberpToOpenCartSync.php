<?php

function WeberpToOpenCartDailySync($ShowMessages, $db, $db_oc, $oc_tableprefix, $EmailText=''){
	$begintime = time_start();

	DB_Txn_Begin($db);

	// check last time we run this script, so we know which records need to update from OC to webERP
	$LastTimeRun = CheckLastTimeRun('WeberpToOpenCartDaily', $db);
	if ($ShowMessages){
		$TimeDifference = Get_SQL_to_PHP_time_difference($db);
		prnMsg('This script was last run on: ' . $LastTimeRun . ' Server time difference: ' . $TimeDifference,'success');
		prnMsg('Server time now: ' . GetServerTimeNow($TimeDifference) ,'success');
	}
	if ($EmailText!=''){
		$EmailText = $EmailText . 'webERP to OpenCart Daily Sync was last run on: ' . $LastTimeRun .  "\n" .
					PrintTimeInformation($db);
	}

	// maintain outlet category in webERP
	// Not needed because now in weberp one item only belongs to 1 sales category, so no chance to have more than one to clean up
//	$EmailText = MaintainWeberpOutletSalesCategories($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText);

	// do all hourly maintenance as well...
	$EmailText = WeberpToOpenCartHourlySync($ShowMessages, $db, $db_oc, $oc_tableprefix, FALSE, $EmailText);

	// recreate the list of featured in OpenCart
	$EmailText = SyncFeaturedList($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText);

	// update sales categories
	$EmailText = SyncSalesCategories($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText);

	// activate / inactivate categories depending on items No items = inactive. Items = Active
	$EmailText = ActivateCategoryDependingOnQOH($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText);

	// maintain the outlet category in a special way (both webERP and OC)
//	$EmailText = MaintainOpenCartOutletSalesCategories($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText);

	// assign multiple images to products
	$EmailText = SyncMultipleImages($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText);

	// assign related items
	$EmailText = SyncRelatedItems($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText);

	// We are done!
	SetLastTimeRun('WeberpToOpenCartDaily', $db);
	DB_Txn_Commit($db);
	if ($ShowMessages){
		time_finish($begintime);
	}

	return $EmailText;
}

function WeberpToOpenCartHourlySync($ShowMessages, $db, $db_oc, $oc_tableprefix, $ControlTx = TRUE, $EmailText=''){
	$begintime = time_start();
	if ($ControlTx){
		DB_Txn_Begin($db);
	}
	// check last time we run this script, so we know which records need to update from OC to webERP
	$LastTimeRun = CheckLastTimeRun('WeberpToOpenCartHourly', $db);
	if ($ShowMessages){
		$TimeDifference = Get_SQL_to_PHP_time_difference($db);
		prnMsg('This script was last run on: ' . $LastTimeRun . ' Server time difference: ' . $TimeDifference,'success');
		prnMsg('Server time now: ' . GetServerTimeNow($TimeDifference) ,'success');
	}
	if (($EmailText!='') AND ControlTx){
		$EmailText = $EmailText . 'webERP to OpenCart Hourly Sync was last run on: ' . $LastTimeRun .  "\n" .
					PrintTimeInformation($db);
	}
	// update product basic information
	$EmailText = SyncProductBasicInformation($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText);

	// update product - sales categories relationship
	$EmailText = SyncProductSalesCategories($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText);

	// update product prices
	$EmailText = SyncProductPrices($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText);

	// update stock in hand
	$EmailText = SyncProductQOH($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText);

	// clean duplicated URL alias
	$EmailText = CleanDuplicatedUrlAlias($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText);

	// We are done!
	SetLastTimeRun('WeberpToOpenCartHourly', $db);
	if ($ControlTx){
		DB_Txn_Commit($db);
	}
	if ($ShowMessages){
		time_finish($begintime);
	}

	return $EmailText;
}

function SyncProductBasicInformation($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText= ''){
	$ServerNow = GetServerTimeNow(Get_SQL_to_PHP_time_difference($db));
	$Today = date('Y-m-d');

	if ($EmailText !=''){
		$EmailText = $EmailText . "Basic Product Information" . "\n" . PrintTimeInformation($db);
	}

	/* let's get the webERP price list and base currency for the online customer */
	list ($PriceList, $Currency) = GetOnlinePriceList($db);

	/* Look for all stockid that have been modified lately */
	$SQL = "SELECT stockmaster.stockid,
				stockmaster.description,
				stockmaster.longdescription,
				stockmaster.discontinued,
				stockmaster.grossweight,
				stockmaster.length,
				stockmaster.width,
				stockmaster.height,
				stockmaster.unitsdimension,
				stockmaster.discountcategory,
				salescatprod.salescatid,
				salescatprod.manufacturers_id
			FROM stockmaster, salescatprod
			WHERE stockmaster.stockid = salescatprod.stockid
				AND ((stockmaster.date_created >= '" . $LastTimeRun . "'	OR stockmaster.date_updated >= '" . $LastTimeRun . "')
					OR (salescatprod.date_created >= '" . $LastTimeRun . "'	OR salescatprod.date_updated >= '" . $LastTimeRun . "'))
			ORDER BY stockmaster.stockid";

	$result = DB_query($SQL, $db);
	if (DB_num_rows($result) != 0){
		if ($ShowMessages){
			echo '<p class="page_title_text" align="center"><strong>' . _('Product Basic Info') .'</strong></p>';
			echo '<div>';
			echo '<table class="selection">';
			$TableHeader = '<tr>
								<th>' . _('StockID') . '</th>
								<th>' . _('Description') . '</th>
								<th>' . _('QOH') . '</th>
								<th>' . _('Basic Price') . '</th>
								<th>' . _('Action') . '</th>
							</tr>';
			echo $TableHeader;
		}
		$DbgMsg = _('The SQL statement that failed was');
		$UpdateErrMsg = _('The SQL to update Basic Product Information in Opencart failed');
		$InsertErrMsg = _('The SQL to insert Basic Product Information in Opencart failed');

		$k = 0; //row colour counter
		$i = 0;
		while ($myrow = DB_fetch_array($result)) {
			if ($ShowMessages){
				$k = StartEvenOrOddRow($k);
			}
			/* Field Matching */
			$Model = $myrow['stockid'];
			$SKU = $myrow['stockid'];
			$UPC = '';
			$EAN = '';
			$JAN = '';
			$ISBN = '';
			$Location = '';
			$Quantity = GetOnlineQOH($myrow['stockid'], $db);
			$StockStatusId = 5; // Out of stock by default
			$Image = PATH_OPENCART_IMAGES . $myrow['stockid'].'.jpg';
			$ManufacturerId = $myrow['manufacturers_id'];
			$Shipping = 1; // will need function depending if it's a shippable or not item
			$CustomerCode = GetWeberpCustomerIdFromCurrency(OPENCART_DEFAULT_CURRENCY, $db);
			$Price = GetPrice($myrow['stockid'], $CustomerCode, $CustomerCode, $db); // Get the price without any discount from webERP
			$DiscountCategory = $myrow['discountcategory'];
			$Points = 0; // No points concept in webERP
			$TaxClassId = 0; // Not sure how to link stockid and tax in webERP
			$DateAvailable = $ServerNow;
			$Weight = $myrow['grossweight'];
			$WeightClassId = 1; //In webERP grossweight is always in Kg.
			$Length = $myrow['length'];
			$Width = $myrow['width'];
			$Height = $myrow['height'];
			$LenghtClassId = GetLenghtClassId($myrow['unitsdimension'], 1, $db_oc, $oc_tableprefix);
			$Subtract = 1;
			$Minimum = 1;
			$SortOrder = 1;
			if ($myrow['discontinued'] == 0){
				/* It's a current item */
				if ($Quantity > 0){
					/* It's current and we have stock available, should be available in website */
					$Status = 1;
				}else{
					/* It's current but we don't have stock available, should not be available in website */
					$Status = 0;
				}
			}else{
				/* It's an obsolete item, not available in website */
				$Status = 0;
			}
			$Viewed = 0;

			$LanguageId = 1;
			$Name = $myrow['description'];
			$Description =  str_replace("'", "\'", $myrow['longdescription']);
			$MetaDescription = CreateMetaDescription($myrow['stockid'], trim($myrow['description']));
			$MetaKeyword = CreateMetaKeyword($myrow['stockid'], trim($myrow['description']));
			$Tag = $myrow['description'];
			$StoreId = 0;

			/* Google Product Feed Fields */
			$MPN = $myrow['stockid'];
			$GPFStatus = GetGoogleProductFeedStatus($myrow['stockid'], $myrow['salescatid'], $Quantity);
			$GoogleProductCategory = GetGoogleProductFeedCategory($myrow['stockid'], $myrow['salescatid']);
			$GoogleBrand = GOOGLE_BRAND;
			$GoogleGender = GOOGLE_GENDER;
			$GoogleAgeGroup = GOOGLE_AGEGROUP;
			$GoogleCondition = GOOGLE_CONDITION;
			$GoogleOosStatus = GOOGLE_OOS_STATUS;
			$GoogleIdentifier = GOOGLE_IDENTIFIER;

			/* END Google Product Feed Fields */

			if (DataExistsInOpenCart($db_oc, $oc_tableprefix . 'product', 'model', $myrow['stockid'])){
				$Action = "Update";
				// Let's get the OpenCart primary key for product
				$ProductId = GetOpenCartProductId($Model, $db_oc, $oc_tableprefix);
				$sqlUpdate = "UPDATE " . $oc_tableprefix . "product SET
								sku = '" . $SKU . "',
								mpn = '" . $MPN . "',
								image = '" . $Image . "',
								status = '" . $Status . "',
                                quantity = '" . $Quantity . "',
								gpf_status = '" . $GPFStatus . "',
								google_product_category = '" . $GoogleProductCategory . "',
								brand = '" . $GoogleBrand . "',
								gender = '" . $GoogleGender . "',
								agegroup = '" . $GoogleAgeGroup . "',
								`condition` = '" . $GoogleCondition . "',
								oos_status = '" . $GoogleOosStatus . "',
								identifier_exists = '" . $GoogleIdentifier . "',
								manufacturer_id = '" . $ManufacturerId . "',
								weight = '" . $Weight . "',
								length = '" . $Length . "',
								width = '" . $Width . "',
								height = '" . $Height . "',
								length_class_id = '" . $LenghtClassId . "'
							WHERE product_id = '" . $ProductId . "'";
				$resultUpdate = DB_query_oc($sqlUpdate,$UpdateErrMsg,$DbgMsg,true);

				$sqlUpdate = "UPDATE " . $oc_tableprefix . "product_description SET
								name = '" . $Name . "',
								description = '" . $Description . "',
								meta_description = '" . $MetaDescription . "',
								meta_keyword = '" . $MetaKeyword . "',
								tag = '" . $Tag . "'
							WHERE product_id = '" . $ProductId . "'
								AND language_id = '" . $LanguageId . "'";
				$resultUpdate = DB_query_oc($sqlUpdate,$UpdateErrMsg,$DbgMsg,true);

				// update discounts if needed
				MaintainOpenCartDiscountForItem($ProductId, $Price, $DiscountCategory, $PriceList, $db, $db_oc, $oc_tableprefix);

				// update SEO Keywords if needed
				$SEOQuery = 'product_id='.$ProductId;
				$SEOKeyword = CreateSEOKeyword($Model . "-" . $Name);
				MaintainUrlAlias($SEOQuery, $SEOKeyword, $db_oc, $oc_tableprefix);

			}else{
				$Action = "Insert";
				$sqlInsert = "INSERT INTO " . $oc_tableprefix . "product
								(model,
								sku,
								upc,
								ean,
								jan,
								isbn,
								mpn,
								location,
								quantity,
								stock_status_id,
								image,
								manufacturer_id,
								shipping,
								price,
								points,
								tax_class_id,
								date_available,
								weight,
								weight_class_id,
								length,
								width,
								height,
								length_class_id,
								subtract,
								minimum,
								sort_order,
								status,
								viewed,
								gpf_status,
								google_product_category,
								brand,
								gender,
								agegroup,
								`condition`,
								oos_status,
								identifier_exists,
								date_added,
								date_modified)
							VALUES
								('" . $Model . "',
								'" . $SKU . "',
								'" . $UPC . "',
								'" . $EAN . "',
								'" . $JAN . "',
								'" . $ISBN . "',
								'" . $MPN . "',
								'" . $Location . "',
								'" . $Quantity . "',
								'" . $StockStatusId . "',
								'" . $Image . "',
								'" . $ManufacturerId . "',
								'" . $Shipping . "',
								'" . $Price . "',
								'" . $Points . "',
								'" . $TaxClassId . "',
								'" . $DateAvailable . "',
								'" . $Weight . "',
								'" . $WeightClassId . "',
								'" . $Length . "',
								'" . $Width . "',
								'" . $Height . "',
								'" . $LenghtClassId . "',
								'" . $Subtract . "',
								'" . $Minimum . "',
								'" . $SortOrder . "',
								'" . $Status . "',
								'" . $Viewed . "',
								'" . $GPFStatus . "',
								'" . $GoogleProductCategory . "',
								'" . $GoogleBrand . "',
								'" . $GoogleGender . "',
								'" . $GoogleAgeGroup . "',
								'" . $GoogleCondition . "',
								'" . $GoogleOosStatus . "',
								'" . $GoogleIdentifier . "',
								'" . $ServerNow . "',
								'" . $ServerNow . "'
								)";
				$resultInsert = DB_query_oc($sqlInsert,$InsertErrMsg,$DbgMsg,true);

				// Let's get the OpenCart primary key for product
				$ProductId = GetOpenCartProductId($Model, $db_oc, $oc_tableprefix);

				$sqlInsert = "INSERT INTO " . $oc_tableprefix . "product_description
								(product_id,
								language_id,
								name,
								description,
								meta_description,
								meta_keyword,
								tag)
							VALUES
								('" . $ProductId . "',
								'" . $LanguageId . "',
								'" . $Name . "',
								'" . $Description . "',
								'" . $MetaDescription . "',
								'" . $MetaKeyword . "',
								'" . $Tag . "'
								)";
				$resultInsert = DB_query_oc($sqlInsert,$InsertErrMsg,$DbgMsg,true);

				$sqlInsert = "INSERT INTO " . $oc_tableprefix . "product_to_store
								(product_id,
								store_id)
							VALUES
								('" . $ProductId . "',
								'" . $StoreId . "'
								)";
				$resultInsert = DB_query_oc($sqlInsert,$InsertErrMsg,$DbgMsg,true);

				// create discounts if needed
				MaintainOpenCartDiscountForItem($ProductId, $Price, $DiscountCategory, $PriceList, $db, $db_oc, $oc_tableprefix);

				// create SEO Keywords if needed
				$SEOQuery = 'product_id='.$ProductId;
				$SEOKeyword = CreateSEOKeyword($Model . "-" . $Name);
				MaintainUrlAlias($SEOQuery, $SEOKeyword, $db_oc, $oc_tableprefix);

				$SortOrder++;
			}
			if ($ShowMessages){
				printf('<td>%s</td>
						<td>%s</td>
						<td>%s</td>
						<td class="number">%s</td>
						<td>%s</td>
						</tr>',
						$Model,
						$Name,
						locale_number_format($Quantity,0),
						locale_number_format($Price,2),
						$Action
						);
			}
			if ($EmailText !=''){
				$EmailText = $EmailText . str_pad($Model, 20, " ") . " = " . $Name. " --> " . $Action . "\n";
			}
			$i++;
		}
		if ($ShowMessages){
			echo '</table>
					</div>
					</form>';
		}
	}
	if ($ShowMessages){
		prnMsg(locale_number_format($i,0) . ' ' . _('Products synchronized from webERP to OpenCart'),'success');
	}
	if ($EmailText !=''){
		$EmailText = $EmailText . locale_number_format($i,0) . ' ' . _('Product Basic Info synchronized from webERP to OpenCart') . "\n\n";
	}
	return $EmailText;
}

function SyncProductSalesCategories($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText= ''){

	if ($EmailText !=''){
		$EmailText = $EmailText . "Product - Sales Categories" . "\n" . PrintTimeInformation($db);
	}

	/* Look for the late modifications of salescatprod table in webERP */
	$SQL = "SELECT salescatprod.salescatid,
				salescatprod.stockid,
				salescatprod.manufacturers_id,
				salescatprod.featured
			FROM salescatprod
			WHERE (salescatprod.date_created >= '" . $LastTimeRun . "'
					OR salescatprod.date_updated >= '" . $LastTimeRun . "')
			ORDER BY salescatprod.salescatid, salescatprod.stockid";

	$result = DB_query($SQL, $db);
	if (DB_num_rows($result) != 0){
		if ($ShowMessages){
			echo '<p class="page_title_text" align="center"><strong>' . _('Product - Sales Categories') .'</strong></p>';
			echo '<div>';
			echo '<table class="selection">';
			$TableHeader = '<tr>
								<th>' . _('StockID') . '</th>
								<th>' . _('Sales Category') . '</th>
								<th>' . _('Manufacturer Id') . '</th>
								<th>' . _('Featured') . '</th>
								<th>' . _('Action') . '</th>
							</tr>';
			echo $TableHeader;
		}
		$DbgMsg = _('The SQL statement that failed was');
		$UpdateErrMsg = _('The SQL to update Product - Sales Categories in Opencart failed');
		$InsertErrMsg = _('The SQL to insert Product - Sales Categories in Opencart failed');

		$k = 0; //row colour counter
		$i = 0;
		while ($myrow = DB_fetch_array($result)) {

			/* Field Matching */
			$Model = $myrow['stockid'];
			$SalesCatId = $myrow['salescatid'];
			$ManufacturerId = $myrow['manufacturers_id'];
			$Featured = $myrow['featured'];
			if($Featured == 1){
				$PrintFeatured = "Yes";
			}else{
				$PrintFeatured = "No";
			}

			// Let's get the OpenCart primary key for product
			$ProductId = GetOpenCartProductId($Model, $db_oc, $oc_tableprefix);
			
			// Delete the current product_to_category, as we now only accept 1 product_to_category in website
			$Action = "Delete";
			$sqlDelete = "DELETE FROM " . $oc_tableprefix . "product_to_category 
						WHERE product_id = '" . $ProductId . "'";
			$resultDelete = DB_query_oc($sqlDelete,$UpdateErrMsg,$DbgMsg,true);

			// Insert the new record
			$Action = "Insert";
			$sqlInsert = "INSERT INTO " . $oc_tableprefix . "product_to_category
							(product_id,
							category_id)
						VALUES
							('" . $ProductId . "',
							'" . $SalesCatId . "'
							)";
			$resultInsert = DB_query_oc($sqlInsert,$InsertErrMsg,$DbgMsg,true);

			if ($ShowMessages){
				$k = StartEvenOrOddRow($k);
				printf('<td>%s</td>
						<td>%s</td>
						<td>%s</td>
						<td>%s</td>
						<td>%s</td>
						</tr>',
						$Model,
						$SalesCatId,
						$ManufacturerId,
						$PrintFeatured,
						$Action
						);
			}
			if ($EmailText !=''){
				$EmailText = $EmailText . str_pad($Model, 20, " ") . " --> " . $SalesCatId. " --> " . $Action . "\n";
			}
			$i++;
		}
		if ($ShowMessages){
			echo '</table>
					</div>
					</form>';
		}
	}
	if ($ShowMessages){
		prnMsg(locale_number_format($i,0) . ' ' . _('Products to Sales Categories synchronized from webERP to OpenCart'),'success');
	}
	if ($EmailText !=''){
		$EmailText = $EmailText . locale_number_format($i,0) . ' ' . _('Product - Sales Categories synchronized from webERP to OpenCart') . "\n\n";
	}
	return $EmailText;
}

function SyncProductPrices($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText = ''){

	if ($EmailText !=''){
		$EmailText = $EmailText . "Product Price Sync" . "\n" . PrintTimeInformation($db);
	}

	/* let's get the webERP price list and base currency for the online customer */
	list ($PriceList, $Currency) = GetOnlinePriceList($db);

	/* Look for the late modifications of prices table in webERP */
	$SQL = "SELECT prices.stockid,
				stockmaster.discountcategory
			FROM prices, stockmaster
			WHERE prices.stockid = stockmaster.stockid
				AND prices.typeabbrev ='" . $PriceList . "'
				AND prices.currabrev ='" . $Currency . "'
				AND (prices.date_created >= '" . $LastTimeRun . "'
					OR prices.date_updated >= '" . $LastTimeRun . "')
			ORDER BY prices.stockid";

	$result = DB_query($SQL, $db);
	if (DB_num_rows($result) != 0){
		if ($ShowMessages){
			echo '<p class="page_title_text" align="center"><strong>' . _('Product Prices Updates') .'</strong></p>';
			echo '<div>';
			echo '<table class="selection">';
			$TableHeader = '<tr>
								<th>' . _('StockID') . '</th>
								<th>' . _('New Price') . '</th>
								<th>' . _('Discount Category') . '</th>
								<th>' . _('Action') . '</th>
							</tr>';
			echo $TableHeader;
		}
		$DbgMsg = _('The SQL statement that failed was');
		$UpdateErrMsg = _('The SQL to update Product Prices in Opencart failed');

		$k = 0; //row colour counter
		$i = 0;
		while ($myrow = DB_fetch_array($result)) {
			$k = StartEvenOrOddRow($k);

			/* Field Matching */
			$Model = $myrow['stockid'];
			$CustomerCode = GetWeberpCustomerIdFromCurrency(OPENCART_DEFAULT_CURRENCY, $db);
			$Price = GetPrice ($myrow['stockid'], $CustomerCode, $CustomerCode, $db); // Get the price without any discount from webERP
			$ManufacturerId = $myrow['manufacturers_id'];
			$DiscountCategory = $myrow['discountcategory'];

			// Let's get the OpenCart primary key for product
			$ProductId = GetOpenCartProductId($Model, $db_oc, $oc_tableprefix);

			$Action = "Update";
			$sqlUpdate = "UPDATE " . $oc_tableprefix . "product SET
							price = '" . $Price . "'
						WHERE product_id = '" . $ProductId . "'";
			$resultUpdate = DB_query_oc($sqlUpdate,$UpdateErrMsg,$DbgMsg,true);

			// update discounts if needed
			MaintainOpenCartDiscountForItem($ProductId, $Price, $DiscountCategory, $PriceList, $db, $db_oc, $oc_tableprefix);
			if ($ShowMessages){
				printf('<td>%s</td>
						<td class="number">%s</td>
						<td>%s</td>
						<td>%s</td>
						</tr>',
						$Model,
						locale_number_format($Price,2),
						$DiscountCategory,
						$Action
						);
			}
			if ($EmailText !=''){
				$EmailText = $EmailText . str_pad($Model, 20, " ") . " = " . locale_number_format($Price,2). " = " . $DiscountCategory . " --> " . $Action . "\n";
			}
			$i++;
		}
		if ($ShowMessages){
			echo '</table>
					</div>
					</form>';
		}
	}
	if ($ShowMessages){
		prnMsg(locale_number_format($i,0) . ' ' . _('Product Prices synchronized from webERP to OpenCart'),'success');
	}
	if ($EmailText !=''){
		$EmailText = $EmailText . locale_number_format($i,0) . ' ' . _('Product Prices synchronized from webERP to OpenCart') . "\n\n";
	}
	return $EmailText;
}

function SyncProductQOH($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText=''){

	if ($EmailText !=''){
		$EmailText = $EmailText . "Sync Product QOH" . "\n" . PrintTimeInformation($db);
	}

	/* let's get the webERP price list and base currency for the online customer */
	list ($PriceList, $Currency) = GetOnlinePriceList($db);

	/* Look for the late modifications of prices table in webERP */
	$SQL = "SELECT DISTINCT(locstock.stockid)
			FROM locstock, salescatprod
			WHERE locstock.stockid = salescatprod.stockid
				AND locstock.loccode IN ('" . str_replace(',', "','", LOCATIONS_WITH_STOCK_FOR_ONLINE_SHOP) . "')
				AND (locstock.date_created >= '" . $LastTimeRun . "'
					OR locstock.date_updated >= '" . $LastTimeRun . "')
			ORDER BY locstock.stockid";

	$result = DB_query($SQL, $db);
	if (DB_num_rows($result) != 0){
		if ($ShowMessages){
			echo '<p class="page_title_text" align="center"><strong>' . _('Product QOH Updates') .'</strong></p>';
			echo '<div>';
			echo '<table class="selection">';
			$TableHeader = '<tr>
								<th>' . _('StockID') . '</th>
								<th>' . _('Online QOH') . '</th>
								<th>' . _('Action') . '</th>
							</tr>';
			echo $TableHeader;
		}
		$DbgMsg = _('The SQL statement that failed was');
		$UpdateErrMsg = _('The SQL to update Product QOH in Opencart failed');

		$k = 0; //row colour counter
		$i = 0;
		while ($myrow = DB_fetch_array($result)) {

			/* Field Matching */
			$Model = $myrow['stockid'];
			$Quantity = GetOnlineQOH($myrow['stockid'], $db);
			if ($Quantity > 0){
				$Status = 1;
				$GPFStatus = 1;
			}else{
				$Status = 0;
				$GPFStatus = 0;
			}

			// Let's get the OpenCart primary key for product
			$ProductId = GetOpenCartProductId($Model, $db_oc, $oc_tableprefix);

			$Action = "Update";
			$sqlUpdate = "UPDATE " . $oc_tableprefix . "product SET
							quantity = '" . $Quantity . "',
							gpf_status = '" . $GPFStatus . "',
							status = '" . $Status . "'
						WHERE product_id = '" . $ProductId . "'";
			$resultUpdate = DB_query_oc($sqlUpdate,$UpdateErrMsg,$DbgMsg,true);
			if ($ShowMessages){
				$k = StartEvenOrOddRow($k);
				printf('<td>%s</td>
						<td class="number">%s</td>
						<td>%s</td>
						</tr>',
						$Model,
						locale_number_format($Quantity,0),
						$Action
						);
			}
			if ($EmailText !=''){
				$EmailText = $EmailText . str_pad($Model, 20, " ") . " QOH = " . locale_number_format($Quantity,0) . "\n";
			}
			$i++;
		}
		if ($ShowMessages){
			echo '</table>
					</div>
					</form>';
		}
	}
	if ($ShowMessages){
		prnMsg(locale_number_format($i,0) . ' ' . _('Product QOH synchronized from webERP to OpenCart'),'success');
	}
	if ($EmailText !=''){
		$EmailText = $EmailText . locale_number_format($i,0) . ' ' . _('Product QOH synchronized from webERP to OpenCart') . "\n\n";
	}

	return $EmailText;
}

function CleanDuplicatedUrlAlias($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText = ''){

	if ($EmailText !=''){
		$EmailText = $EmailText . "Clean Duplicated URL Alias" . "\n" . PrintTimeInformation($db);
	}

	$SQL = "SELECT 	" . $oc_tableprefix . "url_alias.url_alias_id,
				" . $oc_tableprefix . "url_alias.query,
				" . $oc_tableprefix . "url_alias.keyword
		FROM " . $oc_tableprefix . "url_alias
		ORDER BY " . $oc_tableprefix . "url_alias.query,
				" . $oc_tableprefix . "url_alias.url_alias_id DESC";
	$result = DB_query_oc($SQL);
	if (DB_num_rows($result) != 0){
		$k = 0; //row colour counter
		$i = 0;
		$PreviousQuery = "";
		$PreviousKeyword = "";
		$ShowHeader = TRUE;
		while ($myrow = DB_fetch_array($result)) {
			if ($PreviousQuery == $myrow['query']){
				// we have a duplicated
				$DuplicatedQuery = $myrow['query'];
				$DuplicatedKeyword = $myrow['keyword'];

				if ($ShowHeader){
					if ($ShowMessages){
						echo '<p class="page_title_text" align="center"><strong>' . _('Duplicated URL Alias clean up') .'</strong></p>';
						echo '<div>';
						echo '<table class="selection">';
						$TableHeader = '<tr>
											<th>' . _('URL Alias ID') . '</th>
											<th>' . _('Query') . '</th>
											<th>' . _('Keyword') . '</th>
										</tr>';
						echo $TableHeader;
					}
					$ShowHeader = FALSE;
				}
				// we delete the duplicated
				$sqlDelete = "DELETE FROM " . $oc_tableprefix . "url_alias
							WHERE url_alias_id = '" .  $myrow['url_alias_id'] . "'";
				$resultDelete = DB_query_oc($sqlDelete,$UpdateErrMsg,$DbgMsg,true);

				// we set it up as a redirect just in case someome uses this old URL keyword
				if ($PreviousKeyword != $myrow['keyword']){
					$Active = 1;
					$FromURL = PATH_OPENCART_BASE . '/'. $myrow['keyword'];
					$ToURL = PATH_OPENCART_BASE . '/' . ROUTE_TO_PRODUCT . $myrow['query'];
					$ResponseCode = REDIRECT_RESPONSE_CODE;
					$FromDate = date('Y-m-d');
					$TimesUsed = 0;
					$sqlInsert = "INSERT INTO " . $oc_tableprefix . "redirect
								(active,
								from_url,
								to_url,
								response_code,
								date_start,
								times_used)
							VALUES
								('" . $Active . "',
								'" . $FromURL . "',
								'" . $ToURL . "',
								'" . $ResponseCode . "',
								'" . $FromDate . "',
								'" . $TimesUsed . "'
								)";
					$resultInsert = DB_query_oc($sqlInsert,$UpdateErrMsg,$DbgMsg,true);
				}

				if ($ShowMessages){
					$k = StartEvenOrOddRow($k);
					printf('<td class="number">%s</td>
							<td>%s</td>
							<td>%s</td>
							</tr>',
							locale_number_format($myrow['url_alias_id'],0),
							$myrow['query'],
							$myrow['keyword']
							);
				}
				if ($EmailText !=''){
					$EmailText = $EmailText . locale_number_format($myrow['url_alias_id'],0) . " --> " . $myrow['query'] . " --> ". $myrow['keyword'] . "\n";
				}
				$i++;
			}
			$PreviousQuery = $myrow['query'];
			$PreviousKeyword = $myrow['keyword'];
		}
		if (!$ShowHeader){
			if ($ShowMessages){
			echo '</table>
					</div>
					</form>';
			}
		}
	}
	if ($ShowMessages){
		prnMsg(locale_number_format($i,0) . ' ' . _('Duplicated URL Alias synchronized in OpenCart'),'success');
	}
	if ($EmailText !=''){
		$EmailText = $EmailText . locale_number_format($i,0) . ' ' . _('Duplicated URL Alias synchronized in OpenCart') . "\n\n";
	}
	return $EmailText;
}

function SyncSalesCategories($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText= ''){
	$ServerNow = GetServerTimeNow(Get_SQL_to_PHP_time_difference($db));
	if ($EmailText !=''){
		$EmailText = $EmailText . "Sync Sales Categories" . "\n" . PrintTimeInformation($db);
	}

	$SQL = "SELECT salescatid,
				parentcatid,
				salescatname,
				active
			FROM salescat
			WHERE date_created >= '" . $LastTimeRun . "'
				OR date_updated >= '" . $LastTimeRun . "'
			ORDER BY salescatid";

	$result = DB_query($SQL, $db);
	if (DB_num_rows($result) != 0){
		if ($ShowMessages){
			echo '<p class="page_title_text" align="center"><strong>' . _('Sales categories') .'</strong></p>';
			echo '<div>';
			echo '<table class="selection">';
			$TableHeader = '<tr>
								<th>' . _('SalesCatID') . '</th>
								<th>' . _('SalesCatName') . '</th>
								<th>' . _('Action') . '</th>
							</tr>';
			echo $TableHeader;
		}
		$DbgMsg = _('The SQL statement that failed was');
		$UpdateErrMsg = _('The SQL to update sales categories in Opencart failed');
		$InsertErrMsg = _('The SQL to insert sales categories in Opencart failed');

		$k = 0; //row colour counter
		$i = 0;
		while ($myrow = DB_fetch_array($result)) {

			/* FIELD MATCHING */
			if ($myrow['parentcatid'] == 0){
				$Top = 1;
			}else{
				$Top = 0;
			}
			$StoreId = 0;
			$Column = 1;
			$Language_Id = 1; // for now NO multi language
			$SortOrder = 1;
			$Name = trim($myrow['salescatname']);
			$Description = trim($myrow['salescatname']);
			$MetaDescription = CreateMetaDescription('Sales category', trim($myrow['salescatname']));
			$MetaKeyword = CreateMetaKeyword('', trim($myrow['salescatname']));
			$CategoryId = $myrow['salescatid'];
			if (DataExistsInOpenCart($db_oc, $oc_tableprefix . 'category', 'category_id', $myrow['salescatid'])){
				$Action = "Update";
				$sqlUpdate = "UPDATE " . $oc_tableprefix . "category
								SET parent_id 		= '" . $myrow['parentcatid'] . "',
									status 			= '" . $myrow['active'] . "',
									top 			= '" . $Top . "',
									date_modified 	= '" . $ServerNow . "'
								WHERE category_id 	= '" . $CategoryId . "'";
				$resultUpdate = DB_query_oc($sqlUpdate,$UpdateErrMsg,$DbgMsg,true);

				$sqlUpdate = "UPDATE " . $oc_tableprefix . "category_description
								SET language_id 		= '" . $Language_Id . "',
									name	 			= '" . $Name . "'
								WHERE category_id 	= '" . $CategoryId . "'";
				$resultUpdate = DB_query_oc($sqlUpdate,$UpdateErrMsg,$DbgMsg,true);

				// update SEO Keywords if needed
				$SEOQuery = 'category_id='.$CategoryId;
				$SEOKeyword = CreateSEOKeyword($Name);
				MaintainUrlAlias($SEOQuery, $SEOKeyword, $db_oc, $oc_tableprefix);

			}else{
				$Action = "Insert";
				$sqlInsert = "INSERT INTO " . $oc_tableprefix . "category
								(category_id,
								image,
								parent_id,
								top,
								`column`,
								sort_order,
								status,
								date_added,
								date_modified)
							VALUES
								('" . $CategoryId . "',
								'',
								'" . $myrow['parentcatid'] . "',
								'" . $Top . "',
								'" . $Column . "',
								'" . $SortOrder . "',
								'" . $myrow['active'] . "',
								'" . $ServerNow . "',
								'" . $ServerNow . "'
								)";
				$resultInsert = DB_query_oc($sqlInsert,$InsertErrMsg,$DbgMsg,true);
				$sqlInsert = "INSERT INTO " . $oc_tableprefix . "category_description
								(category_id,
								language_id,
								name,
								description,
								meta_description,
								meta_keyword)
							VALUES
								('" . $CategoryId . "',
								'" . $Language_Id . "',
								'" . $Name . "',
								'" . $Description . "',
								'" . $MetaDescription . "',
								'" . $MetaKeyword . "'
								)";
				$resultInsert = DB_query_oc($sqlInsert,$InsertErrMsg,$DbgMsg,true);
				$sqlInsert = "INSERT INTO " . $oc_tableprefix . "category_to_store
								(category_id,
								store_id)
							VALUES
								('" . $CategoryId . "',
								'" . $StoreId . "'
								)";
				$resultInsert = DB_query_oc($sqlInsert,$InsertErrMsg,$DbgMsg,true);
				$SortOrder++;

				// insert SEO Keywords if needed
				$SEOQuery = 'category_id='.$CategoryId;
				$SEOKeyword = CreateSEOKeyword($Name);
				MaintainUrlAlias($SEOQuery, $SEOKeyword, $db_oc, $oc_tableprefix);

			}
			if ($ShowMessages){
				$k = StartEvenOrOddRow($k);
				printf('<td>%s</td>
						<td>%s</td>
						<td>%s</td>
						</tr>',
						$myrow['salescatid'],
						$Name,
						$Action
						);
			}
			if ($EmailText !=''){
				$EmailText = $EmailText . $myrow['salescatid'] . " = " . $Name. " --> " . $Action . "\n";
			}
			$i++;
		}
		if ($ShowMessages){
			echo '</table>
					</div>
					</form>';
		}
	}
	if ($ShowMessages){
		if ($i > 0){
			prnMsg('Remind to run Repair Categories on OpenCart!','warn');
		}
		prnMsg(locale_number_format($i,0) . ' ' . _('Sales Categories synchronized from webERP to OpenCart'),'success');
	}
	if ($EmailText !=''){
		$EmailText = $EmailText . locale_number_format($i,0) . ' ' . _('Sales Categories synchronized from webERP to OpenCart') . "\n\n";
	}
	return $EmailText;
}

function SyncFeaturedList($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText= ''){

	if ($EmailText !=''){
		$EmailText = $EmailText . "Clean Duplicated URL Alias" . "\n" . PrintTimeInformation($db);
	}
	/* Let's get the ID for the list of featured products for featured module
	   we will need it later on to save the results in the appropiate setting */
	$SettingId = GetOpenCartSettingId(0,"featured", "featured_product", $db_oc, $oc_tableprefix);
	$ListFeaturedOpenCart = "";

	/* Look for the featured items in webERP
	we'll recreate the full list everytime as it will be short and
	it's a list that will change quite often */
	$SQL = "SELECT DISTINCT(salescatprod.stockid)
			FROM salescatprod
			WHERE salescatprod.featured ='1'
			ORDER BY salescatprod.stockid";
	$result = DB_query($SQL, $db);
	if (DB_num_rows($result) != 0){
		if ($ShowMessages){
			echo '<p class="page_title_text" align="center"><strong>' . _('Create featured list in OpenCart') .'</strong></p>';
			echo '<div>';
			echo '<table class="selection">';
			$TableHeader = '<tr>
								<th>' . _('StockID') . '</th>
								<th>' . _('OpenCartID') . '</th>
								<th>' . _('Action') . '</th>
							</tr>';
			echo $TableHeader;
		}
		$Action = "Added";
		$k = 0; //row colour counter
		$i = 0;
		while ($myrow = DB_fetch_array($result)) {
			/* Field Matching */
			$Model = $myrow['stockid'];

			// Let's get the OpenCart primary key for product
			$ProductId = GetOpenCartProductId($Model, $db_oc, $oc_tableprefix);

			// Let's build the list
			if ($i == 0){
				$ListFeaturedOpenCart = strval($ProductId);
			}else{
				$ListFeaturedOpenCart = $ListFeaturedOpenCart . "," . strval($ProductId);
			}
			if ($ShowMessages){
				$k = StartEvenOrOddRow($k);
				printf('<td>%s</td>
						<td class="number">%s</td>
						<td>%s</td>
						</tr>',
						$Model,
						$ProductId,
						$Action
						);
			}
			if ($EmailText !=''){
				$EmailText = $EmailText . str_pad($Model, 20, " ") . " = " . $ProductId. " --> " . $Action . "\n";
			}
			$i++;
		}
		UpdateSettingValueOpenCart($SettingId, $ListFeaturedOpenCart, $db_oc, $oc_tableprefix);
		if ($ShowMessages){
			echo '</table>
					</div>
					</form>';
		}
	}
	if ($ShowMessages){
		prnMsg(locale_number_format($i,0) . ' ' . _('Products included in the featured list in OpenCart'),'success');
	}
	if ($EmailText !=''){
		$EmailText = $EmailText . locale_number_format($i,0) . ' ' . _('Products included in the featured list in OpenCart') . "\n\n";
	}
	return $EmailText;
}

function ActivateCategoryDependingOnQOH($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText= ''){

	if ($EmailText !=''){
		$EmailText = $EmailText . "Activate category Depending on QOH" . "\n" . PrintTimeInformation($db);
	}
	$SQL = "SELECT salescatid,
				parentcatid,
				salescatname,
				active,
                (SELECT SUM(locstock.quantity)
					FROM salescatprod,locstock
					WHERE salescat.salescatid = salescatprod.salescatid
						AND salescatprod.stockid = locstock.stockid
						AND locstock.loccode IN ('" . str_replace(',', "','", LOCATIONS_WITH_STOCK_FOR_ONLINE_SHOP) . "')
				) as qoh
			FROM salescat
			WHERE active = 1
				AND parentcatid != 0
			ORDER BY salescatname";
	$result = DB_query($SQL, $db);
	if (DB_num_rows($result) != 0){
		if ($ShowMessages){
			echo '<p class="page_title_text" align="center"><strong>' . _('Activate/Inactivate Categories depending on QOH') .'</strong></p>';
			echo '<div>';
			echo '<table class="selection">';
			$TableHeader = '<tr>
								<th>' . _('Sales Category') . '</th>
								<th>' . _('QOH') . '</th>
								<th>' . _('Action') . '</th>
							</tr>';
			echo $TableHeader;
		}
		$DbgMsg = _('The SQL statement that failed was');
		$UpdateErrMsg = _('The SQL to Activate Categories depending QOH in Opencart failed');

		$k = 0; //row colour counter
		$i = 0;
		while ($myrow = DB_fetch_array($result)) {

			/* Field Matching */
			$CategoryId = $myrow['salescatid'];
			$CategoryName = $myrow['salescatname'];
			$CategoryQOH = $myrow['qoh'];

            if (isset($myrow['qoh'])){
                if ($CategoryQOH > 0){
                    $CategoryQOH = $myrow['qoh'];
                    $Status = 1;
                    $Action = "Active";
                }else{
                    $CategoryQOH = 0;
                    $Status = 0;
                    $Action = "Inactive QOH = 0";
                }
            }else{
                $CategoryQOH = 0;
                $Status = 0;
                $Action = "Inactive QOH = 0";
            }

			$sqlUpdate = "UPDATE " . $oc_tableprefix . "category SET
								status = '" . $Status . "'
							WHERE category_id = '" . $CategoryId . "'";
			$resultUpdate = DB_query_oc($sqlUpdate,$UpdateErrMsg,$DbgMsg,true);
			if ($ShowMessages){
				$k = StartEvenOrOddRow($k);
				printf('<td>%s</td>
						<td class="number">%s</td>
						<td>%s</td>
						</tr>',
						$CategoryName,
						locale_number_format($CategoryQOH,0),
						$Action
						);
			}
			if ($EmailText !=''){
				$EmailText = $EmailText . $CategoryName . " --> " . locale_number_format($CategoryQOH,0) . " --> " . $Action . "\n";
			}
			$i++;
		}
		if ($ShowMessages){
			echo '</table>
					</div>
					</form>';
		}
	}
	if ($ShowMessages){
		prnMsg(locale_number_format($i,0) . ' ' . _('OpenCart Categories Activated / Inactivated depending on QOH'),'success');
	}
	if ($EmailText !=''){
		$EmailText = $EmailText . locale_number_format($i,0) . ' ' . _('OpenCart Categories Activated / Inactivated depending on QOH') . "\n\n";
	}
	return $EmailText;
}

function MaintainOpenCartOutletSalesCategories($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText = ''){

	/* Look for all products in OC marked as OUTLET and "something else"*/
	$SQL = "SELECT " . $oc_tableprefix . "product.product_id,
				   " . $oc_tableprefix . "product.model
			FROM " . $oc_tableprefix . "product_to_category ,
				 " . $oc_tableprefix . "product
			WHERE " . $oc_tableprefix . "product.product_id = " . $oc_tableprefix . "product_to_category.product_id
				AND category_id IN (" . OPENCART_OUTLET_CATEGORIES . ")";
		$result = DB_query_oc($SQL);
	if (DB_num_rows($result) != 0){
		if ($ShowMessages){
			echo '<p class="page_title_text" align="center"><strong>' . _('Maintain Outlet Sales Categories') .'</strong></p>';
			echo '<div>';
			echo '<table class="selection">';
			$TableHeader = '<tr>
								<th>' . _('StockID') . '</th>
								<th>' . _('Action') . '</th>
							</tr>';
			echo $TableHeader;
		}
		$DbgMsg = _('The SQL statement that failed was');
		$UpdateErrMsg = _('The SQL to update Product QOH in Opencart failed');

		$k = 0; //row colour counter
		$i = 0;
		while ($myrow = DB_fetch_array($result)) {

			$ProductId = $myrow['product_id'];
			$Model = $myrow['model'];

			$Action = "Delete sales categories not OUTLET";
			$sqlDelete = "DELETE FROM " . $oc_tableprefix . "product_to_category
							WHERE product_id = '" . $ProductId . "'
								AND category_id NOT IN (" . OPENCART_OUTLET_CATEGORIES . ")";
			$resultDelete = DB_query_oc($sqlDelete,$UpdateErrMsg,$DbgMsg,true);
			if ($ShowMessages){
				$k = StartEvenOrOddRow($k);
				printf('<td>%s</td>
						<td>%s</td>
						</tr>',
						$Model,
						$Action
						);
			}
/*			if ($EmailText !=''){
				$EmailText = $EmailText . str_pad($Model, 20, " ") . " --> " . $Action . "\n";
			}
*/			$i++;
		}
		if ($ShowMessages){
			echo '</table>
					</div>
					</form>';
		}
	}
	if ($EmailText !=''){
		$EmailText = $EmailText . locale_number_format($i,0) . ' ' . _('OpenCart Outlet Sales Categories maintained') . "\n\n";
	}
	return $EmailText;
}

function MaintainWeberpOutletSalesCategories($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText=''){

	/* Look for all products in weberp marked as OUTLET and "something else"*/

	$SQL = "SELECT salescatprod.stockid
			FROM salescatprod
			WHERE salescatprod.salescatid IN (" . WEBERP_OUTLET_CATEGORIES . ")";
		$result = DB_query($SQL, $db);
	if (DB_num_rows($result) != 0){
		if ($ShowMessages){
			echo '<p class="page_title_text" align="center"><strong>' . _('Maintain webERP Outlet Sales Categories') .'</strong></p>';
			echo '<div>';
			echo '<table class="selection">';
			$TableHeader = '<tr>
								<th>' . _('StockID') . '</th>
								<th>' . _('Action') . '</th>
							</tr>';
			echo $TableHeader;
		}
		$DbgMsg = _('The SQL statement that failed was');
		$UpdateErrMsg = _('The SQL to update outlet sales category in webERP failed');

		$k = 0; //row colour counter
		$i = 0;
		while ($myrow = DB_fetch_array($result)) {
			$k = StartEvenOrOddRow($k);

			$ProductId = $myrow['stockid'];

			$Action = "Delete sales categories not OUTLET";
			$sqlDelete = "DELETE FROM salescatprod
							WHERE stockid = '" . $ProductId . "'
								AND salescatid NOT IN (" . WEBERP_OUTLET_CATEGORIES . ")";
			$resultDelete = DB_query($sqlDelete,$db,$UpdateErrMsg,$DbgMsg,true);
			if ($ShowMessages){
				printf('<td>%s</td>
						<td>%s</td>
						</tr>',
						$ProductId,
						$Action
						);
			}
/*			if ($EmailText !=''){
				$EmailText = $EmailText . str_pad($ProductId, 20, " ") . " --> " . $Action . "\n";
			}
*/			$i++;
		}
		if ($ShowMessages){
			echo '</table>
					</div>
					</form>';
		}
	}
	if ($EmailText !=''){
		$EmailText = $EmailText . locale_number_format($i,0) . ' ' . _('webERP Outlet Sales Categories Maintained') . "\n\n";
	}
	return $EmailText;
}

function SyncMultipleImages($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText = ''){

	if ($EmailText !=''){
		$EmailText = $EmailText . "Sync Multiple Images" . "\n" . PrintTimeInformation($db);
	}

	if ($ShowMessages){
		echo '<p class="page_title_text" align="center"><strong>' . _('Synchronize multiple images per item') .'</strong></p>';
		echo '<div>';
		echo '<table class="selection">';
		$TableHeader = '<tr>
							<th>' . _('webERP Code') . '</th>
							<th>' . _('File') . '</th>
						</tr>';
		echo $TableHeader;
	}
	$SQLTruncate = "TRUNCATE " . $oc_tableprefix . "product_image";
	$resultSQLTruncate = DB_query_oc($SQLTruncate);

	$k = 0; //row colour counter
	$i= 0;
	// get all images in part_pics folder (ideally should be OpenCart images folder...)
	$imagefiles = getDirectoryTree($_SESSION['part_pics_dir'], 'jpg');
	foreach ($imagefiles as $file) {
		$multipleimage = 1;
		$exist_multiple = TRUE;
		while ($multipleimage <= 5){
			$suffix = ".". $multipleimage;
			if (strpos($file, $suffix) > 0){
				// GET stockid from filename
				$StockId = substr($file, 0, strpos($file, $suffix));
				// get Opencart productid
				$ProductId = GetOpenCartProductId($StockId, $db_oc, $oc_tableprefix);
				if ($ProductId > 0){
					// insert info about multiple images
					$sqlInsert = "INSERT INTO " . $oc_tableprefix . "product_image
									(product_id,
									image,
									sort_order)
								VALUES
									('" . $ProductId . "',
									'" . PATH_OPENCART_IMAGES . $file . "',
									'" . $multipleimage . "')";
					$resultInsert = DB_query_oc($sqlInsert,$InsertErrMsg,$DbgMsg,true);
					if ($ShowMessages){
						$k = StartEvenOrOddRow($k);
						printf('<td>%s</td>
								<td>%s</td>
								</tr>',
								$StockId,
								$file
								);
					}
					$i++;
				}
			}
			$multipleimage++;
		}
	}
	if ($ShowMessages){
		echo '</table>
				</div>
				</form>';
		prnMsg(locale_number_format($i,0) . ' ' . _('Multiple Images Synchronized'),'success');
	}
	if ($EmailText !=''){
		$EmailText = $EmailText . locale_number_format($i,0) . ' ' . _('Multiple Images Synchronized') . "\n\n";
	}
	return $EmailText;
}

function SyncRelatedItems($ShowMessages, $LastTimeRun, $db, $db_oc, $oc_tableprefix, $EmailText = ''){

	if ($EmailText !=''){
		$EmailText = $EmailText . "Sync Related Items" . "\n" . PrintTimeInformation($db);
	}

	$SQL = "SELECT stockid,
				related
			FROM relateditems
			WHERE date_created >= '" . $LastTimeRun . "'
				OR date_updated >= '" . $LastTimeRun . "'
			ORDER BY stockid, related";

	$result = DB_query($SQL, $db);
	if (DB_num_rows($result) != 0){
		if ($ShowMessages){
			echo '<p class="page_title_text" align="center"><strong>' . _('Related Items') .'</strong></p>';
			echo '<div>';
			echo '<table class="selection">';
			$TableHeader = '<tr>
								<th>' . _('Item webERP') . '</th>
								<th>' . _('Related webERP') . '</th>
								<th>' . _('Item OC') . '</th>
								<th>' . _('Related OC') . '</th>
								<th>' . _('Action') . '</th>
							</tr>';
			echo $TableHeader;
		}
		$DbgMsg = _('The SQL statement that failed was');
		$UpdateErrMsg = _('The SQL to update related items in Opencart failed');
		$InsertErrMsg = _('The SQL to insert related items in Opencart failed');

		$k = 0; //row colour counter
		$i = 0;
		while ($myrow = DB_fetch_array($result)) {
			$k = StartEvenOrOddRow($k);

			/* FIELD MATCHING */
			$ProductId = GetOpenCartProductId($myrow['stockid'], $db_oc, $oc_tableprefix);
			$RelatedId = GetOpenCartProductId($myrow['related'], $db_oc, $oc_tableprefix);

			if (DataExistsInOpenCart($db_oc, $oc_tableprefix . 'product_related', 'product_id', $ProductId, 'related_id', $RelatedId )){
				$Action = "Update";
			}else{
				$Action = "Insert";
				$sqlInsert = "INSERT INTO " . $oc_tableprefix . "product_related
								(product_id,
								related_id)
							VALUES
								('" . $ProductId . "',
								'" . $RelatedId . "'
								)";
				$resultInsert = DB_query_oc($sqlInsert,$InsertErrMsg,$DbgMsg,true);
			}
			if ($ShowMessages){
				printf('<td>%s</td>
						<td>%s</td>
						<td>%s</td>
						<td>%s</td>
						<td>%s</td>
						</tr>',
						$myrow['stockid'],
						$myrow['related'],
						$ProductId,
						$RelatedId,
						$Action
						);
			}
			$i++;
		}
		if ($ShowMessages){
			echo '</table>
					</div>
					</form>';
		}
	}
	if ($ShowMessages){
		prnMsg(locale_number_format($i,0) . ' ' . _('Pairs of related items synchronized from webERP to OpenCart'),'success');
	}
	if ($EmailText !=''){
		$EmailText = $EmailText . locale_number_format($i,0) . ' ' . _('Pairs of related items synchronized from webERP to OpenCart') . "\n\n";
	}
	return $EmailText;
}

?>