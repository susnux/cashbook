/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export interface ICashbook {
	/** The Snowflake ID, a string as it exceeds the safe integer range */
	id: string
	name: string
	account: string
	currency: string
	/** The user id of the owner */
	owner: string
	/** The IANA timezone ID, the business days of the cashbook are calendar days in this timezone */
	timezone: string
	/** The sequence number of the last entry, 0 if there are no entries */
	lastSequence: number
	/** The cash balance after the last entry as decimal string */
	balance: string
}

/**
 * The properties of a cashbook that can be set by the user
 */
export type ICashbookData = Pick<ICashbook, 'name' | 'account' | 'currency' | 'timezone'>

export class Cashbook implements ICashbook {
	public id: string
	public name: string
	public account: string
	public currency: string
	public owner: string
	public timezone: string
	public lastSequence: number
	public balance: string

	public constructor(data: ICashbook) {
		this.id = data.id
		this.account = data.account
		this.name = data.name
		this.currency = data.currency
		this.owner = data.owner
		this.timezone = data.timezone
		this.lastSequence = data.lastSequence
		this.balance = data.balance
	}
}
